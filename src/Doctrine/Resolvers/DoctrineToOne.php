<?php

namespace RateHub\GraphQL\Doctrine\Resolvers;

use RateHub\GraphQL\Interfaces\IGraphQLResolver;

use RateHub\GraphQL\Doctrine\DoctrineDeferredBuffer;
use RateHub\GraphQL\Doctrine\GraphHydrator;

use Doctrine\ORM\Query;

/**
 * Class DoctrineToOne
 *
 * Resolver handles N-to-One doctrine relationships.
 * Leverages the DoctrineDeferredBuffer and GraphDeferred to delay querying for the new data until
 * all fields have been processed at which point GraphQLPHP process the deferred fields. During
 * the deferred field resolution the buffer is populated 'once' with the required data.
 * Reduces the number of queries from N to 1.
 *
 * @package App\Api\GraphQL\Resolvers
 *
 */
class DoctrineToOne implements IGraphQLResolver {

	/**
	 * @var String	Field name as it will be output in GraphQL
	 */
	private $name;

	/**
	 * @var String	The description of for the field. The description is used in
	 * 				the Introspective api
	 */
	private $description;

	/**
	 * @var String	The doctrine type defined as the full class name for the target
	 * type.
	 */
	private $doctrineClass;

	/**
	 * @var String the name of the type as defined in graphql. This is also the key
	 * for any mappings done by the DoctrineProvider
	 */
	private $graphName;

	/**
	 * @var Array	The doctrine association object that defines the relationship
	 */
	private $association;

	/**
	 * @var	DoctrineProvider	Instance of the doctrine provider that instantiated this resolver.
	 */
	private $typeProvider;

	/**
	 * @var string The key that is used for storing the buffer.
	 */
	private $bufferKey;

	/**
	 * DoctrineToOne constructor.
	 * @param $provider 		Instance of the doctrine provider
	 * @param $name				Field name for the relationship as it should be output in GraphQL
	 * @param $description		The description of the field to be used by the introspective api
	 * @param $entityType		Class name for the entity
	 * @param $association		Doctrine Association object
	 */
	public function __construct($provider, $name, $description, $doctrineClass, $graphName,  $association){

		$this->name 			= $name;
		$this->description 		= $description;
		$this->doctrineClass	= $doctrineClass;
		$this->graphName		= $graphName;
		$this->association 		= $association;
		$this->typeProvider 	= $provider;

		$this->bufferKey = $this->graphName . '.' . $this->name;

	}

	/**
	 * Generate the definition for the GraphQL field
	 *
	 * @return array
	 */
	public function getDefinition(){

		// Resolve the types with the provider
		$outputType = $this->typeProvider->getType($this->graphName);
		$filterType = $this->typeProvider->getFilterType($this->graphName);

		$args = array();

		// Generate the argument definition based on the filter type.
		// No type means the type has no inputs
		if($filterType !== null) {
			foreach ($filterType->getFields() as $field) {
				$args[$field->name] = array('name' => $field->name, 'type' => $field->getType());
			}
		}

		// Create and return the definition array
		return array(
			'name' => $this->name,
			'type' => $outputType,
			'args' => $args,
			'resolve' => function($parent, $args, $context, $info){

				// Get the refenced it without triggering the doctrine auto hydration
				// This is why we need the GraphEntity to act as a proxy.
				//$identifier = (is_object($parent) ? $parent->getDataValue($sourceColumn) : $parent[$sourceColumn]);


				$targetIdentifiers = $this->typeProvider->getTypeIdentifiers($this->graphName);

				$identifier = [];

				$doctrineType = $this->typeProvider->getDoctrineType($this->graphName);

				foreach($targetIdentifiers as $field){

					if($doctrineType->hasAssociation($field)) {

						$fieldAssociation = $doctrineType->getAssociationMapping($field);

						$fieldName = $fieldAssociation['joinColumns'][0]['name'];

						$identifier[$field] = $parent->getDataValue($fieldName);

					}else{

						$targetToSource = $this->association['sourceToTargetKeyColumns'];

						$sourceColumn = $this->association['fieldName'];

						if($targetToSource != null)
							$sourceColumn = array_keys($targetToSource)[0];

						$identifier[$field] = (is_object($parent) ? $parent->getDataValue($sourceColumn) : $parent[$sourceColumn]);

					}

				}

				if($identifier != null) {

					// Initialize the buffer, if initialized use the existing one
					$buffer = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $this->bufferKey);

					$buffer->add($identifier);

					// GraphQLPHP will call the deferred resolvers as needed.
					return new \GraphQL\Deferred(function() use ($buffer, $identifier, $args) {

						// Populate the buffer with the loaded data
						$this->loadBuffered($args, $identifier);

						$em = $this->typeProvider->getManager();

						$graphHydrator = new GraphHydrator($em);

						$result = null;

						// Retrieve the result from the buffer
						$data = $buffer->result(implode(':', array_values($identifier)));

						// Create a GraphEntity and Hydrate the doctrine object
						if ($data !== null)
							$result = $graphHydrator->hydrate($data, $this->doctrineClass);

						// Return the GraphEntity
						return $result;

					});

				}

				return null;

			}

		);

	}

	/**
	 * @param $args		   The filter arguments for this entity passed via the GraphQL query
	 * @param $identifier  Identifier for the parent object as this is an N-to-one relationship
	 */
	public function loadBuffered($args, $identifier){

		// Fetch the buffer associated with this type
		$buffer 	  = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $this->bufferKey);

		// Have we already loaded to data, if not proceed
		if(!$buffer->isLoaded()) {

			// Create a query using the arguments passed in the query
			$queryBuilder = $this->typeProvider->getRepository($this->doctrineClass)->createQueryBuilder('e');

			// Single key
			if(count(array_keys($identifier)) == 1) {

				$mappedBy = array_keys($identifier)[0];

				$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $mappedBy, ':' . $mappedBy));
				$queryBuilder->setParameter($mappedBy, $buffer->get());

			// Composite key
			}else{

				$etype = $this->typeProvider->getDoctrineType($this->graphName);

				$cnt = 0;

				$orConditions = [];

				foreach($buffer->get() as $parentIdentifier){

					$recordConditions = [];

					foreach($parentIdentifier as $fieldName => $fieldValue){

						array_push($recordConditions, $queryBuilder->expr()->eq('e.' . $fieldName, ':' . $fieldName . $cnt));

						$queryBuilder->setParameter($fieldName . $cnt, $fieldValue);

					}

					$andX = $queryBuilder->expr()->andX();
					$andX->addMultiple($recordConditions);

					array_push($orConditions, $andX);

					$cnt++;

				}

				$orX = $queryBuilder->expr()->orX();
				$orX->addMultiple($orConditions);

				$queryBuilder->andWhere($orX);

			}

			foreach ($args as $name => $values) {

				$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $name, ':' . $name));
				$queryBuilder->setParameter($name, $values);

			}

			$query = $queryBuilder->getQuery();
			$query->setHint(Query::HINT_INCLUDE_META_COLUMNS, true); // Include associations

			// Use array hydration only. GraphHydrator will handle hydration of doctrine objects.
			$results = $query->getResult(Query::HYDRATE_ARRAY);

			$resultsLoaded = array();

			$doctrineType = $this->typeProvider->getDoctrineType($this->graphName);

			// Group the results by the parent entity
			foreach ($results as $result) {

				$targetIdentifiers = $this->typeProvider->getTypeIdentifiers($this->graphName);

				$parentValues = [];

				foreach($targetIdentifiers as $field){

					if($doctrineType->hasAssociation($field)) {

						$fieldAssociation = $doctrineType->getAssociationMapping($field);

						$fieldName = $fieldAssociation['joinColumns'][0]['name'];

						array_push($parentValues, $result[$fieldName]);

					}else{

						$fieldName = $doctrineType->getColumnName($field);

						array_push($parentValues, $result[$fieldName]);

					}

				}

				$resultsLoaded[implode(':', $parentValues)] = $result;

			}

			// Load the buffer with the results.
			$buffer->load($resultsLoaded);

		}

	}


}