<?php

namespace RateHub\GraphQL\Doctrine;

use RateHub\GraphQL\Interfaces\IGraphQLProvider;

use Doctrine\ORM\EntityManagerInterface;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;

use RateHub\GraphQL\Doctrine\Resolvers\DoctrineField;
use RateHub\GraphQL\Doctrine\Resolvers\DoctrineMethod;
use RateHub\GraphQL\Doctrine\Resolvers\DoctrineToMany;
use RateHub\GraphQL\Doctrine\Resolvers\DoctrineToOne;

use Doctrine\Common\Annotations\SimpleAnnotationReader;


/**
 * Class DoctrineProvider
 *
 * Generates all the necessary types, queries and mutators using the Doctrine
 * model metadata. All standard data fetching uses the doctrine Array Hydrator. This provider
 * will handle the Object hydration once all queries are finished and the data is ready to be returned.
 *
 * Features:
 *
 * Whitelist/Blacklist. The provider can be setup to filter what objects and properties are
 * 						included in the final graphql api.
 *  					Whitelisting will only include types/properties that are marked for inclusion
 * 						Blacklisting will only include types/properties that are not marked for exclusion
 *
 * Naming Overrides. 	Names and descriptions of types and methods can be overwritten via the
 * 						GraphQLType and GraphQLProperty annotations. Field Names are not yet able to be overwritten.
 *
 * Deferred Loading. 	Associations take advantage of deferred loading the a DeferredBuffer. This is used
 *						to significantly reduce the number of queries and solves the N+1 problem when
 *						loading data (https://secure.phabricator.com/book/phabcontrib/article/n_plus_one/)
 *
 * Custom Resolvers.	Any property can have it's own custom resolver instead of the standard one provided
 * 						by the provider.
 *
 * Pagination.			The provider supports key and offset pagination for top level queries. Also supports limiting
 * 						results returned in an n-to-Many relationship.
 *
 * Polymorphic Entities	Provider provides support for querying polymorphic entities and quering unique fields per type
 * 						using the GraphQL inline fragments.
 *
 *
 * @package App\Api\GraphQL
 */
class DoctrineProvider Implements IGraphQLProvider {

	/**
	 * When filtering using a whitelist, only items explicitly defined for
	 * inclusion are included. Inclusion is done via the GraphQL annotation
	 */
	const FILTER_WHITELIST = 'whitelist';

	/**
	 * When filtering using a whitelist, only items not explicitly defined for
	 * exclusion are included. Exclusion is done via the GraphQL annotation
	 */
	const FILTER_BLACKLIST = 'blacklist';


	/**
	 * @var string	The name of the provider as stored in the Laravel container
	 */
	private $_name = null;

	/**
	 * @var string	The base config path for this instance of the provider
	 */
	private $_configBase = null;

	/**
	 * @var string
	 */
	private $_filter = null;

	/**
	 * @var array    Associative array of defined GraphQL types organized by type name
	 */
	private $_types = array();

	/**
	 * @var array    Associative array of input types. Input types are used for create, update, delete operations
	 */
	private $_inputTypes = array();

	/**
	 * @var array    Associative array of input filter types. Input filters are used to pass association filters for read
	 *               operations.
	 */
	private $_inputFilterTypes = array();

	/**
	 * @var array	Associative array of input query filter types. Input query filters are used to pass query filters
	 * 				for read operations.
	 */
	private $_inputQueryFilterTypes = array();

	/**
	 * @var array    Associative array of the primary keys for each of the objects.
	 */
	private $_identifierFields = array();

	/**
	 * @var EntityManagerInterface    Doctrine Entity Manager
	 */
	private $_em;

	/**
	 * @var array    Associative array of the Doctrine type metadata
	 */
	public $_doctrineMetadata = array();

	/**
	 * @var array    Deferred data buffers
	 */
	private $_dataBuffers = array();


	private $_options;


	/**
	 * DoctrineTypeRegistry constructor.
	 * @param EntityManagerInterface $em
	 */
	public function __construct($name, $options) {

		$this->_name = $name;

		$this->_options = $options;

		// Define the base config path for convenience
		$this->_configBase = 'graphql.providers.' . $name;

		// Unless otherwise specified then run in whitelist mode.
		// Only items that are marked for inclusion will be added to the api
		$this->_filter = ($options->filter === self::FILTER_BLACKLIST ? self::FILTER_BLACKLIST : self::FILTER_WHITELIST );

		$this->_em = $options->em;

		// Need to first initialize scalars before moving on to object types because
		// of dependencies
		$this->initializeCustomScalars();

		// Initialize core types, includes standard PageInfo type used for pagination
		$this->initializeCoreTypes();

		// Build base object types based on the doctrine metadata
		foreach ($this->_em->getMetadataFactory()->getAllMetadata() as $metaType) {

			// Ignore superclasses as they cannot be instantiated so always ignore;
			if ($metaType->isMappedSuperclass)
				continue;

			$reader = new SimpleAnnotationReader();

			$class = $metaType->getReflectionClass();

			$annotation = $reader->getClassAnnotation($class, 'RateHub\Annotation\GraphQLType');

			// Run the type through the whitelist/blacklist filter
			if($this->filter($annotation)) {

				$this->_doctrineMetadata[$metaType->getName()] = $metaType;

				$this->initializeObjectType($metaType, $annotation);

			}

		}

	}

	/**
	 * Perform the blacklist/whitelist filtering based on the current setting. Check
	 * the annotation to determine if the item should be included or not.
	 *
	 * @param $annotation
	 * @return bool
	 */
	private function filter($annotation){

		// Currently running in whitelist mode. Only items marked for inclusion will
		// be included
		if($this->_filter === self::FILTER_WHITELIST){

			if($annotation !== null && $annotation->include === true)
				return true;

			// Currently running in blacklist mode. Items marked for exclusion will not
			// be included in the final type list
		}else if($this->_filter === self::FILTER_BLACKLIST){

			if($annotation !== null && $annotation->include !== false) {

				return true;

			}else if($annotation === null){

				return true;

			}

		}

		// Defaults to false
		return false;

	}

	/**
	 * Register the scalar types defined in the config. GraphQLPHP requires only
	 * one instance per type to be used when linking data types.
	 */
	private function initializeCustomScalars() {

	    if($this->_options->scalars !== null) {

            foreach ($this->_options->scalars as $name => $type) {
                $this->_types[$name] = new $type();
            }

        }

	}

	/**
	 * Initialize core types
	 */
	private function initializeCoreTypes(){

		$this->_types[GraphPageInfo::NAME] = GraphPageInfo::getType();

	}

	/**
	 * Initializes the complex object types based on the doctrine metadata.
	 *
	 * @param $entityMetaType
	 * @param $annotation
	 */
	private function initializeObjectType($entityMetaType, $annotation) {

		// Type definition
		$config = array();

		$name = $entityMetaType->getName();

		// If we've already defined this type then move on
		if (isset($this->_types[$name]))
			return;

		// Store the identifier fields for convenience later
		$this->_identifierFields[$name] = $entityMetaType->getIdentifier();

		// Set the name via annotation or use the default
		if($annotation !== null && $annotation->name !== null) {
			$config['name'] = $annotation->name;
		}else {
			$config['name'] = str_replace('\\', '__', $name); // GraphQL Spec requires [A-Za-z0-9_-]
		}

		// Set the description via the annotation if set.
		if($annotation !== null && $annotation->description !== null) {
			$config['description'] = $annotation->description;
		}

		// Fields are properties of the objects
		$fields = array();

		// Filter fields are used by the filter input type.
		// Typically seen in the arguments section of a graphql query
		// eg. query testQuery { LoginUser(filterField:[1]){ id } }
		$filterFields = array();

		$filterFields = array_merge($filterFields, GraphPageInfo::getFilters());

		// Top level query filters
		// Typically has more options including key and offset pagination.
		$queryFilterFields = array();

		$queryFilterFields = array_merge($queryFilterFields, GraphPageInfo::getQueryFilters());

		// Input field are used by mutators. Similar to how regular fields are used
		$inputFields = array();

		// Initialize fields that map to entity methods using the annotation reader
		$reader = new SimpleAnnotationReader();

		$class = $entityMetaType->getReflectionClass();

		/* -----------------------------------------------
		 * FIELDS
		 * Process each of the fields on the entity. This does not include associations
		 */
		foreach ($entityMetaType->getFieldNames() as $fieldName) {

			// Map the field to a specific scalar type.
			$fieldType = $this->mapFieldType($entityMetaType->getTypeOfField($fieldName));

			// Attempt to retrieve the property annotation
			$annotation = $reader->getPropertyAnnotation($class->getProperty($fieldName), 'RateHub\Annotation\GraphQLProperty');

			// Check to see if this property should be included via the
			// blacklist/whitelist filtering
			if($this->filter($annotation)) {

				// Check to see if we have a resolver override for the current property
				$resolverClass 		= null;
				$fieldDescription 	= null;

				if ($annotation !== null) {

					if ($annotation->resolver !== null) {
						$resolverClass = $annotation->resolver;
					}

					if ($annotation->description !== null) {
						$fieldDescription = $annotation->description;
					}

				}

				// No override then use the default field resolver;
				if ($resolverClass === null)
					$resolverClass = DoctrineField::class;

				// Instantiate the resolver
				$resolver = new $resolverClass($fieldName, $fieldType, $fieldDescription);

				// Get the definition
				$fields[$fieldName] = $resolver->getDefinition();

				// Define the filters
				$filterFields[$fieldName] = array(
					'name' => $fieldName,
					'type' => Type::listOf($fieldType)
				);

				// Define the top level query filters
				$queryFilterFields[$fieldName] = array(
					'name' => $fieldName,
					'type' => Type::listOf($fieldType)
				);

				// Define the input properties
				$inputFields[$fieldName] = array(
					'name' => $fieldName,
					'type' => $fieldType
				);

			}

		}

		/* -----------------------------------------------
		 * ASSOCIATION FIELDS
		 * Generate fields for filter and input types based on n-to-ONE
		 * associations.
		 */
		foreach ($entityMetaType->getAssociationMappings() as $association) {

			$fieldName = $association['fieldName'];

			// No override then use the default field resolver;
			if ($resolverClass === null)
				$resolverClass = DoctrineField::class;

			// Instantiate the resolver
			$resolver = new $resolverClass($fieldName, $fieldType, $fieldDescription);

			// Get the definition
			$fields[$fieldName] = $resolver->getDefinition();

			// Define the filters
			$filterFields[$fieldName] = array(
				'name' => $fieldName,
				'type' => Type::listOf($fieldType)
			);

			// Define the query filters
			$queryFilterFields[$fieldName] = array(
				'name' => $fieldName,
				'type' => Type::listOf($fieldType)
			);

			// Define the input properties
			$inputFields[$fieldName] = array(
				'name' => $fieldName,
				'type' => $fieldType
			);

		}


		/* -----------------------------------------------
		 * METHODS
		 * Process each of the methods on the entity. Determine if a method is to
		 * be included as a property
		 */
		foreach ($class->getMethods() AS $method) {

			// Attempt to retrieve the annotation
			$annotation = $reader->getMethodAnnotation($method, 'RateHub\Annotation\GraphQLProperty');

			$methodName = $method->name;

			// Check to see if this property should be included via the
			// blacklist/whitelist filtering
			if($this->filter($annotation)) {

				if ($annotation !== null) {

					$fieldName = null;
					$fieldType = null;
					$fieldDescription = null;
					$resolverClass = null;

					// Get the name to use for the property
					if ($annotation->name !== null) {
						$fieldName = $annotation->name;
					}

					// Get the type to use for the property
					if ($annotation->type !== null) {
						$fieldType = $this->mapFieldType($annotation->type);
					}

					if ($annotation->description !== null) {
						$fieldDescription = $annotation->description;
					}

					if ($annotation->resolver !== null) {
						$resolverClass = $annotation->resolver;
					}

					// Both name and type are required to add this property
					// to the GraphQL type.
					if ($fieldName !== null && $fieldType !== null) {

						// Use the default method resolver if not override has been set
						if ($resolverClass === null)
							$resolverClass = DoctrineMethod::class;

						// Instantiate the resolver
						$resolver = new $resolverClass($fieldName, $fieldType, $fieldDescription, $methodName);

						$fields[$fieldName] = $resolver->getDefinition();

					}

				}

			}

		}

		/* -----------------------------------------------
		 * ASSOCIATIONS
		 * Process each of the associations on the entity. Determine if the association is to
		 * be included as a property
		 */
		$config['fields'] = function() use ($entityMetaType, $fields) {

			foreach ($entityMetaType->getAssociationMappings() as $association) {

				$reader = new SimpleAnnotationReader();

				$class = $entityMetaType->getReflectionClass();

				$fieldName = $association['fieldName'];

				// Attempt to get the annotation on the field
				$annotation = $reader->getPropertyAnnotation($class->getProperty($fieldName), 'RateHub\Annotation\GraphQLProperty');

				// Check to see if this property should be included
				if($this->filter($annotation)) {

					$entityType = $association['targetEntity'];

					// Only include this field if the "target entity" has also passed the inclusion filter.
					if (isset($this->_doctrineMetadata[$entityType])) {

						$fieldDescription 	= null;
						$resolver 			= null;

						if($annotation->description !== null){
							$fieldDescription = $annotation->description;
						}

						// Determine the association type and instantiate the appropriate default resolver
						if (!$entityMetaType->isAssociationInverseSide($fieldName)) {

							$resolver = new DoctrineToOne($this, $fieldName, $fieldDescription, $entityType, $association);

						} else {

							$resolver = new DoctrineToMany($this, $fieldName, $fieldDescription, $entityType, $association);

						}

						$fields[$fieldName] = $resolver->getDefinition();

					}

				}

			}

			return $fields;

		};

		// Check to see if we should treat this type as an interface
		// If not an interface then we need to check if the class
		// implements one. If so then the list of interfaces
		// needs to be defined on the type.
		if(!$this->isInterface($entityMetaType)) {

			if(count($entityMetaType->parentClasses) > 0){

				$interfaces = [];

				foreach($entityMetaType->parentClasses as $parent){
					array_push($interfaces, $this->getType($parent));
				}

				if(count($interfaces) > 0)
					$config['interfaces'] = $interfaces;

			}

			// Instantiate the object type
			$this->_types[$name] = new ObjectType($config);


		// If it is an interface (model is polymorphic) then we need to declare it
		// as such and include a type resolver. The type resolver looks at the discriminator column
		// to determine what output type the result is.
		}else{

			$config['resolveType'] = function($value) use ($entityMetaType){

				$column = $entityMetaType->discriminatorColumn['fieldName'];

				$type = $value->getDataValue($column);

				// Based on the discriminator column get the class name
				$instanceType = $entityMetaType->discriminatorMap[$type];

				return $this->getType($instanceType);

			};

			// Instantiate the interface
			$this->_types[$name] = new InterfaceType($config);

		}

		// Instantiate the filter type
		if (count($filterFields) > 0)
			$this->_inputFilterTypes[$name] = new InputObjectType(array(name => $config['name'] . '__Filter', 'fields' => $filterFields));

		// Instantiate the query filter type
		if (count($queryFilterFields) > 0)
			$this->_inputQueryFilterTypes[$name] = new InputObjectType(array(name => $config['name'] . '__QueryFilter', 'fields' => $queryFilterFields));

		// Instantiate the input type
		if (count($inputFields) > 0)
			$this->_inputTypes[$name] = new InputObjectType(array(name => $config['name'] . '__Input', 'fields' => $inputFields));


	}

	/**
	 * Should the entityMetaType be handled as an interface.
	 *
	 * @param $entityMetaType
	 * @return bool
	 */
	private function isInterface($entityMetaType){

		return !(count($entityMetaType->subClasses) === 0);

	}

	/**
	 * Map a doctrine type to it's representative GraphQL type.
	 *
	 * @param $doctrineType Type name as defined on Doctrine object.
	 * @return ScalarType a scalar type.
	 */
	private function mapFieldType($doctrineType) {

		if ($doctrineType === 'integer') {
			return Type::int();
		} else if ($doctrineType === 'boolean') {
			return Type::boolean();
		} else if ($doctrineType === 'float') {
			return Type::float();
		} else if ($doctrineType === 'decimal') {
			return Type::float();
		} else if ($doctrineType === 'json_array') {
			return $this->getType('json');
		} else if ($doctrineType === 'hstore') {
			return $this->getType('hstore');
		} else if ($doctrineType === 'location') {
			return $this->getType('json');
		}

		// Default to string
		return Type::string();

	}

	/**
	 * Return a GraphQL filter type by the model class name
	 *
	 * @param $typeName
	 * @return mixed
	 */
	public function getFilterType($typeName) {

		return $this->_inputFilterTypes[$typeName];

	}

	/**
	 * Return a GraphQL query filter type by the model class name
	 *
	 * @param $typeName
	 * @return mixed
	 */
	public function getQueryFilterType($typeName) {

		return $this->_inputQueryFilterTypes[$typeName];

	}

	/**
	 * Return a GraphQL input type by the model class name
	 *
	 * @param $typeName
	 * @return mixed
	 */
	public function getInputType($typeName) {

		return $this->_inputTypes[$typeName];

	}

	/**
	 * Add a GraphQL output type by the provided name
	 *
	 * @param $typeName
	 * @param $type
	 */
	public function addType($typeName, $type){

		$this->_types[$typeName] = $type;

	}

	/**
	 * Return a GraphQL type by the model class name
	 *
	 * @param $typeName
	 * @return mixed
	 */
	public function getType($typeName) {

		return $this->_types[$typeName];

	}

	/**
	 * Return all GraphQL types
	 *
	 * @return array
	 */
	public function getTypes() {

		return $this->_types;

	}

	/**
	 * Return graphql type keys (model class names)
	 *
	 * @return array
	 */
	public function getTypeKeys() {

		return array_keys($this->_types);

	}

	/**
	 * Convenience method to get the list of identifier fields
	 * for a model type.
	 *
	 * @param $typeName  the model's class name
	 * @return mixed
	 */
	public function getTypeIdentifiers($typeName) {

		return $this->_identifierFields[$typeName];

	}

	/**
	 * Generate a list of queries given the generated graphql types
	 *
	 * @return array
	 */
	public function getQueries(){

		$queries = array();

		// Get custom queries from the config
		$customQueries = $this->_options->queries;

		// Check to see if we have any overrides, if not then use the
		// default query generator only.
		if($customQueries === null || count($customQueries) === 0){

			$customQueries = [DoctrineQueries::class];

		}

		// Loop through all the query builders and generate a list of queries.
		foreach($customQueries as $customQuery){

			$queryInstance = new $customQuery($this);

			$queries = array_merge($queries, $queryInstance->getQueries());

		}

		return $queries;

	}

	/**
	 * Generate a list of mutators given the generated graphql types
	 *
	 * @return array
	 */
	public function getMutators(){

		$mutators = array();

		// Get custom queries from the config
		$customMutators = $this->_options->mutators;

		if($customMutators === null || count($customMutators) === 0){

			$customMutators = array(DoctrineMutators::class);

		}

		// Check to see if we have any overrides, if not then use the
		// default mutator generator only.
		foreach($customMutators as $customMutator){

			$mutatorInstance = new $customMutator($this);

			$mutators = array_merge($mutators, $mutatorInstance->getMutators());

		}

		return $mutators;

	}

	/**
	 * Initialize a buffer for a given type and identifier
	 *
	 * @param $bufferType
	 * @param $key
	 * @return mixed
	 */
	public function initBuffer($bufferType, $key) {

		if (!isset($this->_dataBuffers[$key]))
			$this->_dataBuffers[$key] = new $bufferType();

		return $this->_dataBuffers[$key];

	}

    /**
	 * Return the EntityManager for this provider
	 *
     * @return EntityManagerInterface
     */
	public function getManager(){
		return $this->_em;
	}

}