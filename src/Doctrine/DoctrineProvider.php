<?php

namespace RateHub\GraphQL\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\StringType;
use RateHub\GraphQL\Interfaces\IGraphQLFilterable;
use RateHub\GraphQL\Interfaces\IGraphQLProvider;

use Doctrine\ORM\EntityManagerInterface;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;

use RateHub\GraphQL\Doctrine\Authorization\AuthorizationHandler;
use RateHub\GraphQL\Doctrine\Authorization\ObjectPermission;
use RateHub\GraphQL\Doctrine\Authorization\FieldPermission;
use RateHub\GraphQL\Doctrine\Types\DateTimeType;
use RateHub\GraphQL\Doctrine\Types\BigIntType;

use RateHub\GraphQL\Doctrine\Resolvers\DoctrineField;
use RateHub\GraphQL\Doctrine\Resolvers\DoctrineMethod;
use RateHub\GraphQL\Doctrine\Resolvers\DoctrineToMany;
use RateHub\GraphQL\Doctrine\Resolvers\DoctrineToOne;

use Doctrine\Common\Annotations\SimpleAnnotationReader;

use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\DirectiveLocation;
use GraphQL\Type\Definition\FieldArgument;

use RateHub\GraphQL\Doctrine\Filters\FilterDateTimeBetween;
use RateHub\GraphQL\Doctrine\Filters\FilterDateTime;
use RateHub\GraphQL\Doctrine\Filters\FilterString;
use RateHub\GraphQL\Doctrine\Filters\FilterNumber;


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
 * Namespaces			Along with white and blacklists types and properties can be associated to different namespaces
 * 						and specify CRED operations that are allowed for those namespaces.
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
	 * Class path for the type annotation
	 */
	const ANNOTATION_TYPE 		= '\RateHub\GraphQL\Doctrine\Annotations\GraphQLType';

	/**
	 * Class path for the property annotation
	 */
	const ANNOTATION_PROPERTY 	= '\RateHub\GraphQL\Doctrine\Annotations\GraphQLProperty';

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
	 * @var array    Associative array of graphql type names to doctrine entity classes
	 */
	private $_typeClass = array();

	/**
	 * @var array	Mapping of a doctrine class to the final resolved name
	 */
	private $_doctrineToName = array();

	/**
	 * @var array    Associative array of input types. Input types are used for create, update, delete operations
	 */
	private $_inputTypes = array();

	/**
	 * @var array	Associative array of the input types name to the graph type name/key.
	 */
	private $_inputTypesToName = array();

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

	/**
	 * @var SimpleAnnotationReader
	 */
	private $_reader;

	/**
	 * @var array	Configuration options
	 */
	private $_options;

	/**
	 * @var array	List of permissions per type
	 */
	private $_permissionsByType;

	/**
	 * @var AuthorizationHandler Verifies a users permissions
	 */
	private $_authorizeHandler;


	/**
	 * DoctrineTypeRegistry constructor.
	 * @param EntityManagerInterface $em
	 */
	public function __construct($name, $options, $authorizeHandler = null) {

		$this->_name = $name;

		$this->_options = $options;

		if($authorizeHandler == null) {
			$this->_authorizeHandler = new AuthorizationHandler();
		}else{
			$this->_authorizeHandler = $authorizeHandler;
		}

		$this->_permissionsByType = [];

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

		$this->_reader = new SimpleAnnotationReader();
		$this->_reader->addNamespace('RateHub\GraphQL\Doctrine\Annotations');

		// Build base object types based on the doctrine metadata
		foreach ($this->_em->getMetadataFactory()->getAllMetadata() as $metaType) {

			// Ignore superclasses as they cannot be instantiated so always ignore;
			if ($metaType->isMappedSuperclass)
				continue;

			$class = $metaType->getReflectionClass();

			$annotation = $this->_reader->getClassAnnotation($class, self::ANNOTATION_TYPE);

			$name = $this->getGraphName($metaType, $annotation);

			// Run the type through the whitelist/blacklist filter
			// Run the type through the authorization provider
			$permissions = $this->initObjectPermissions($name,  $annotation);

			if($permissions->hasRead()) {

				$this->initializeObjectType($metaType, $annotation, $permissions);

			}

		}

	}

	/**
	 * Initialize the different object level permissions.
	 *
	 * @param $name
	 * @param $annotation
	 * @return mixed
	 */
	private function initObjectPermissions($name, $annotation){

		$permissions = new ObjectPermission($name);

		$permissions->setCreate($this->verifyAccess($permissions->getCreatePermissionName(), $annotation));
		$permissions->setRead($this->verifyAccess($permissions->getReadPermissionName(), $annotation));
		$permissions->setEdit($this->verifyAccess($permissions->getEditPermissionName(), $annotation));
		$permissions->setDelete($this->verifyAccess($permissions->getDeletePermissionName(), $annotation));

		return $permissions;

	}

	private function initFieldPermissions($objectPermissions, $name, $annotation){

		$fieldPermissions = new FieldPermission($objectPermissions->name . '.' . $name);

		$objectPermissions->addFieldPermission($fieldPermissions);

		$fieldPermissions->setRead($this->verifyAccess($fieldPermissions->getReadPermissionName(), $annotation));
		$fieldPermissions->setEdit($this->verifyAccess($fieldPermissions->getEditPermissionName(), $annotation));

		return $fieldPermissions;

	}


	/**
	 * Perform the blacklist/whitelist filtering and generate namespace permissions based on the current setting. Check
	 * the annotation to determine if various operations should be allowed or not.
	 *
	 * @param $annotation
	 * @return Permission
	 */
	private function verifyAccess($name, $annotation){

		$hasAccess = false;

		// Currently running in whitelist mode. Only items marked for inclusion will
		// be included
		if($this->_filter === self::FILTER_WHITELIST){

			if($annotation !== null && $annotation->include === true) {

				// Passed  Whitelist filter, check namespaces
				$hasAccess = $this->_authorizeHandler->hasAccess($name);

			}

			// Currently running in blacklist mode. Items marked for exclusion will not
			// be included in the final type list
		}else if($this->_filter === self::FILTER_BLACKLIST){

			if($annotation !== null && $annotation->include !== false) {

				// Passed Blacklist filter, check authorization provider
				$hasAccess = $this->_authorizeHandler->hasAccess($name);

			}else if($annotation === null){

				// Passed Blacklist filter, check authorization provider
				$hasAccess = $this->_authorizeHandler->hasAccess($name);

			}

		}

		return $hasAccess;

	}

	/**
	 * Register the scalar types defined in the config. GraphQLPHP requires only
	 * one instance per type to be used when linking data types.
	 */
	private function initializeCustomScalars() {

	    if($this->_options->scalars !== null) {

            foreach ($this->_options->scalars as $name => $type) {
                $this->_types[$name] = new $type();

                // If the additional type supports filters then add them to the
				// list of types.
                if($this->_types[$name] instanceof IGraphQLFilterable){

                	foreach($this->_types[$name]->getFilters($this) as $filterName => $filter){
						$this->_types[$filterName] = $filter;
					}

				}

            }

        }

	}

	/**
	 * Initialize core types
	 */
	private function initializeCoreTypes(){

		$this->_types[GraphPageInfo::NAME]	= GraphPageInfo::getType();
		$this->_types[GraphSortField::NAME]	= GraphSortField::getType();
		$this->_types[FilterString::NAME]	= FilterString::getType();
		$this->_types[FilterNumber::NAME . 'int'] = FilterNumber::getType(Type::int());
		$this->_types[FilterDateTimeBetween::NAME]	= FilterDateTimeBetween::getType($this->getType('datetime'));
		$this->_types[FilterDateTime::NAME]	= FilterDateTime::getType($this->getType('datetime'), $this->getType(FilterDateTimeBetween::NAME));

	}

	private function getGraphName($entityMetaType, $annotation){

		$doctrineClass = $entityMetaType->getName();
		$name = str_replace('\\', '__', $doctrineClass);

		// Set the name via annotation if an override is set
		if($annotation !== null && $annotation->name !== null) {
			$name = $annotation->name;
		}

		return $name;

	}

	/**
	 * Initializes the complex object types based on the doctrine metadata.
	 *
	 * @param $entityMetaType
	 * @param $annotation
	 */
	private function initializeObjectType($entityMetaType, $annotation, $permissions) {

		// Type definition
		$config = array();

		$doctrineClass = $entityMetaType->getName();

		$name = $this->getGraphName($entityMetaType, $annotation);

		// Setup some core data
		$this->_permissionsByType[$name] 	= $permissions;
		$this->_doctrineMetadata[$name]		= $entityMetaType;
		$this->_typeClass[$name] 			= $doctrineClass;

		$this->storeTypeName($doctrineClass, $name);

		// If we've already defined this type then move on
		if (isset($this->_types[$name]))
			return;

		$config['name'] = $name;

		// Store the identifier fields for convenience later
		$this->_identifierFields[$name] = $entityMetaType->getIdentifier();

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

		// Top level query filters
		// Typically has more options including key and offset pagination.
		$queryFilterFields = array();

		$queryFilterFields = array_merge($queryFilterFields, GraphPageInfo::getQueryFilters($this));

		// Input field are used by mutators. Similar to how regular fields are used
		$inputFields = array();

		$class = $entityMetaType->getReflectionClass();

		/* -----------------------------------------------
		 * FIELDS
		 * Process each of the fields on the entity. This does not include associations
		 */
		foreach ($entityMetaType->getFieldNames() as $fieldName) {

			// Map the field to a specific scalar type.
			$fieldType = $this->mapFieldType($entityMetaType->getTypeOfField($fieldName));

			// Attempt to retrieve the property annotation
			$annotation = $this->_reader->getPropertyAnnotation($class->getProperty($fieldName), self::ANNOTATION_PROPERTY);

			$propertyPermission = $this->initFieldPermissions($permissions, ($annotation->name === null ? $fieldName : $annotation->name), $annotation);

			// Check to see if this property should be included via the
			// blacklist/whitelist filtering
			if($propertyPermission->hasRead()) {

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
				if($fieldType instanceof StringType) {
					$queryFilterFields[$fieldName] = array(
						'name' => $fieldName,
						'type' => $this->getType(FilterString::NAME)
					);
				}else if($fieldType instanceof DateTimeType) {
					$queryFilterFields[$fieldName] = array(
						'name' => $fieldName,
						'type' => $this->getType(FilterDateTime::NAME)
					);
				}else if($fieldType instanceof BigIntType) {
					$queryFilterFields[$fieldName] = array(
						'name' => $fieldName,
						'type' => $this->getType(FilterNumber::NAME . 'bigint')
					);
				}else if($fieldType instanceof IntType){
					$queryFilterFields[$fieldName] = array(
						'name' => $fieldName,
						'type' => $this->getType(FilterNumber::NAME . 'int')
					);
				}else{
					$queryFilterFields[$fieldName] = array(
						'name' => $fieldName,
						'type' => Type::listOf($fieldType)
					);
				}

				// Define the input properties
				$inputFields[$fieldName] = array(
					'name' => $fieldName,
					'type' => $fieldType
				);

			}

		}

		/* -----------------------------------------------
		 * METHODS
		 * Process each of the methods on the entity. Determine if a method is to
		 * be included as a property
		 */
		foreach ($class->getMethods() AS $method) {

			// Attempt to retrieve the annotation
			$annotation = $this->_reader->getMethodAnnotation($method, self::ANNOTATION_PROPERTY);

			$methodName = $method->name;

			// Need to have an annotation and a name defined for the method
			if($annotation != null && $annotation->name !== null) {

				// Check to see if this property should be included via the
				// blacklist/whitelist filtering
				$propertyPermissions = $this->initFieldPermissions($permissions, $annotation->name, $annotation);

				if ($propertyPermissions->hasRead()) {

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

		}



		/* -----------------------------------------------
		 * ASSOCIATIONS
		 * Process each of the associations on the entity. Determine if the association is to
		 * be included as a property
		 */
		$config['fields'] = function() use ($entityMetaType, $fields, $permissions) {

			foreach ($entityMetaType->getAssociationMappings() as $association) {

				$class = $entityMetaType->getReflectionClass();

				$fieldName = $association['fieldName'];

				// Attempt to get the annotation on the field
				$annotation = $this->_reader->getPropertyAnnotation($class->getProperty($fieldName), self::ANNOTATION_PROPERTY);

				$propertyPermissions = $this->initFieldPermissions($permissions, ($annotation->name === null ? $fieldName : $annotation->name), $annotation);

				// Check to see if this property should be included
				if($propertyPermissions->hasRead()) {

					$doctrineClass = $association['targetEntity'];
					$graphName	   = $this->getTypeName($doctrineClass);

					// Only include this field if the "target entity" has also passed the inclusion filter.
					if (isset($this->_doctrineMetadata[$graphName])) {

						$fieldDescription 	= null;
						$resolver 			= null;

						if($annotation->description !== null){
							$fieldDescription = $annotation->description;
						}

						// Determine the association type and instantiate the appropriate default resolver
						if ($association['type'] === ClassMetadataInfo::ONE_TO_ONE || $association['type'] === ClassMetadataInfo::MANY_TO_ONE){

							$resolver = new DoctrineToOne($this, $fieldName, $fieldDescription, $doctrineClass, $graphName, $association);

						} else {

							$resolver = new DoctrineToMany($this, $fieldName, $fieldDescription, $doctrineClass, $graphName, $association);

						}

						if($resolver !== null)
							$fields[$fieldName] = $resolver->getDefinition();

					}

				}

			}

			return $fields;

		};


		// If this class has subclasses then we need
        // to create a interface type for it (if not abstract) and the subclasses

        $interfaces = [];

        // Only use the phpgraphql interfaces if a class has subclasses and the class isn't in it's
        // on descriminator map
		if($this->hasSubClasses($entityMetaType) && !$this->inOwnDiscriminatorMap($entityMetaType)){

            $interfaceConfig = $config;

            $interfaceConfig['resolveType'] = function($value) use ($entityMetaType){

                $column = $entityMetaType->discriminatorColumn['fieldName'];

                $type = $value->getDataValue($column);

                // Based on the discriminator column get the class name
                $instanceType = $entityMetaType->discriminatorMap[$type];

                return $this->getType($this->getTypeName($instanceType));

            };

            // Instantiate the interface
            $this->_types[$name] = new InterfaceType($interfaceConfig);

            array_push($interfaces, $this->getType($name));

            $config['interfaces'] = $interfaces;

        }

        // If this class has parent classes then we want to add the parent classes
        if($this->hasParentClasses($entityMetaType)){

            foreach($entityMetaType->parentClasses as $parent){

            	$parentName = $this->getTypeName($parent);

                if($this->getType($parentName) instanceof InterfaceType) {
                    array_push($interfaces, $this->getType($parentName));
                }

            }

            if(count($interfaces) > 0)
                $config['interfaces'] = $interfaces;

        }

        // Instantiate the object type if it's not an abstract type
        if(!($this->hasSubClasses($entityMetaType) && !$this->inOwnDiscriminatorMap($entityMetaType))) {
            $this->_types[$name] = new ObjectType($config);
        }


		/* -----------------------------------------------
		 * ASSOCIATION INPUT FILTERS
		 * Generate fields for filter and input types based on n-to-ONE
		 * associations.
		 */

		$inputFilterConfig = [
			'name' => $config['name'] . '__Filter',
			'fields' => function() use ($entityMetaType, $filterFields, $class, $permissions) {

				foreach ($entityMetaType->getAssociationMappings() as $association) {

					if($association['type'] == ClassMetadataInfo::MANY_TO_ONE || $association['type'] == ClassMetadataInfo::ONE_TO_ONE) {

						$fieldName = $association['fieldName'];

						$fieldType = $this->getInputType($this->getTypeName($association['targetEntity']));

						// Attempt to get the annotation on the field
						$annotation = $this->_reader->getPropertyAnnotation($class->getProperty($fieldName), self::ANNOTATION_PROPERTY);

						$propertyPermissions = $this->initFieldPermissions($permissions, ($annotation->name === null ? $fieldName : $annotation->name), $annotation);

						// Check to see if this property should be included
						if ($propertyPermissions->hasRead()) {

							// Define the filters
							$filterFields[$fieldName] = array(
								'name' => $fieldName,
								'type' => $fieldType
							);

						}

					}

				}

				return $filterFields;

			}
		];


		// Instantiate the filter type
		if (count($filterFields) > 0)
			$this->_inputFilterTypes[$name] = new InputObjectType($inputFilterConfig);


		/* -----------------------------------------------
		 * ASSOCIATION INPUT QUERY FILTER
		 * Generate fields for filter and input types based on n-to-ONE
		 * associations.
		 */

		$inputQueryFilterConfig = [
			'name' => $config['name'] . '__QueryFilter',
			'fields' => function() use ($entityMetaType, $queryFilterFields, $class, $permissions) {

				foreach ($entityMetaType->getAssociationMappings() as $association) {

					if($association['type'] == ClassMetadataInfo::MANY_TO_ONE || $association['type'] == ClassMetadataInfo::ONE_TO_ONE) {

						$fieldName = $association['fieldName'];

						$fieldType = $this->getInputType($this->getTypeName($association['targetEntity']));

						// Attempt to get the annotation on the field
						$annotation = $this->_reader->getPropertyAnnotation($class->getProperty($fieldName), self::ANNOTATION_PROPERTY);

						$propertyPermissions = $this->initFieldPermissions($permissions, ($annotation->name === null ? $fieldName : $annotation->name), $annotation);

						// Check to see if this property should be included
						if ($propertyPermissions->hasRead()) {

							// Define the input properties
							$queryFilterFields[$fieldName] = array(
								'name' => $fieldName,
								'type' => $fieldType
							);

						}

					}

				}

				return $queryFilterFields;

			}
		];

		// Instantiate the query filter type
		if (count($queryFilterFields) > 0)
			$this->_inputQueryFilterTypes[$name] = new InputObjectType($inputQueryFilterConfig);


		/* -----------------------------------------------
		 * ASSOCIATION INPUT
		 * Generate fields for input types based on n-to-ONE
		 * associations.
		 */

		$inputConfig = [
			'name' => $config['name'] . '__Input',
			'fields' => function() use ($entityMetaType, $inputFields, $class, $permissions) {

				foreach ($entityMetaType->getAssociationMappings() as $association) {

					if($association['type'] == ClassMetadataInfo::MANY_TO_ONE || $association['type'] == ClassMetadataInfo::ONE_TO_ONE) {

						$fieldName = $association['fieldName'];

						$fieldType = $this->getInputType($this->getTypeName($association['targetEntity']));

						// Attempt to get the annotation on the field
						$annotation = $this->_reader->getPropertyAnnotation($class->getProperty($fieldName), self::ANNOTATION_PROPERTY);

						$propertyPermissions = $this->initFieldPermissions($permissions, ($annotation->name === null ? $fieldName : $annotation->name), $annotation);

						// Check to see if this property should be included
						if ($propertyPermissions->hasRead()) {

							// Define the input properties
							$inputFields[$fieldName] = array(
								'name' => $fieldName,
								'type' => $fieldType
							);

						}
					}else if($association['type'] == ClassMetadataInfo::ONE_TO_MANY || $association['type'] == ClassMetadataInfo::MANY_TO_MANY) {

						$fieldName = $association['fieldName'];

						$fieldType = $this->getInputType($this->getTypeName($association['targetEntity']));

						// Attempt to get the annotation on the field
						$annotation = $this->_reader->getPropertyAnnotation($class->getProperty($fieldName), self::ANNOTATION_PROPERTY);

						$propertyPermissions = $this->initFieldPermissions($permissions, ($annotation->name === null ? $fieldName : $annotation->name), $annotation);

						// Check to see if this property should be included
						if ($propertyPermissions->hasRead()) {

							$inputFields[$fieldName] = array(
								'name' => $fieldName,
								'type' => Type::listOf($fieldType)
							);

						}

					}

				}

				return $inputFields;

			}
		];


		// Instantiate the input type
		if (count($inputFields) > 0) {
			$this->_inputTypes[$name] = new InputObjectType($inputConfig);
			$this->_inputTypesToName[$inputConfig['name']] = $name;
		}

	}

	/**
	 * Should the entityMetaType be handled as an interface.
	 *
	 * @param $entityMetaType
	 * @return bool
	 */
	private function hasSubClasses($entityMetaType){

		return !(count($entityMetaType->subClasses) === 0);

	}

	private function hasParentClasses($entityMetaType){

	    return !(count($entityMetaType->parentClasses) === 0);

    }

    // If a class is in it's own discriminator map then we don't want
    // to use the php-graphql interfaces
    private function inOwnDiscriminatorMap($entityMetaType){
        foreach($entityMetaType->discriminatorMap as $key => $class){
            if($class === $entityMetaType->name) {
                return true;
            }
        }
        return false;
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
		} else if ($doctrineType === 'datetime') {
			return $this->getType('datetime');
		} else if ($doctrineType === 'date') {
			return $this->getType('datetime');
		} else if ($doctrineType === 'array'){
			return $this->getType('array');
		} else if ($doctrineType === 'bigint'){
			return $this->getType('bigint');
		} else if ($doctrineType === 'smallint'){
			return Type::int();
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

		if(isset($this->_inputFilterTypes[$typeName]))
			return $this->_inputFilterTypes[$typeName];
		return null;

	}

	/**
	 * Return a GraphQL query filter type by the model class name
	 *
	 * @param $typeName
	 * @return mixed
	 */
	public function getQueryFilterType($typeName) {

		if(isset($this->_inputQueryFilterTypes[$typeName]))
			return $this->_inputQueryFilterTypes[$typeName];
		return null;

	}

	/**
	 * Return a GraphQL input type by the model class name
	 *
	 * @param $typeName
	 * @return mixed
	 */
	public function getInputType($typeName) {

		if(isset($this->_inputTypes[$typeName]))
			return $this->_inputTypes[$typeName];
		return null;

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

		if(isset($this->_types[$typeName]))
			return $this->_types[$typeName];

		return null;
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

	public function getInputTypeKey($inputName){

		return $this->_inputTypesToName[$inputName];

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

	public function getDirectives(){

		$directives = array();

		// TODO

		return $directives;

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

	public function getDoctrineType($type){
		return $this->_doctrineMetadata[$type];
	}

    /**
	 * Return the EntityManager for this provider
	 *
     * @return EntityManagerInterface
     */
	public function getManager(){
		return $this->_em;
	}

	/**
	 * Get the permissions object for the type
	 *
	 * @param $type
	 */
	public function getPermissions($name){

		if(isset($this->_permissionsByType[$name]))
			return $this->_permissionsByType[$name];

		return new ObjectPermission();

	}

	public function getAllPermissions(){

		$permissions = [];

		foreach($this->_permissionsByType as $name => $permission){
			$permissions = array_merge($permissions, $permission->getAllPermissions());
		}

		return $permissions;

	}

    public function getRepository($class) {
        return $this->_em->getRepository($class);
    }

    public function clearBuffers(){
       $this->_dataBuffers = array();
    }

    public function getTypeName($className){

    	$key = str_replace('\\', '__', $className);

		return $this->_doctrineToName[$key];

	}

	public function storeTypeName($className, $name){
    	$key = str_replace('\\', '__', $className);

    	$this->_doctrineToName[$key] = $name;
	}

	public function getTypeClass($graphName){
		return $this->_typeClass[$graphName];
	}

}
