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
	 * @var String	The entity type defined as the full class name.
	 */
	private $entityType;

	/**
	 * @var Array	The doctrine association object that defines the relationship
	 */
	private $association;

	/**
	 * @var	DoctrineProvider	Instance of the doctrine provider that instantiated this resolver.
	 */
	private $typeProvider;

	/**
	 * DoctrineToOne constructor.
	 * @param $provider 		Instance of the doctrine provider
	 * @param $name				Field name for the relationship as it should be output in GraphQL
	 * @param $description		The description of the field to be used by the introspective api
	 * @param $entityType		Class name for the entity
	 * @param $association		Doctrine Association object
	 */
	public function __construct($provider, $name, $description, $entityType, $association){

		$this->name 		= $name;
		$this->description 	= $description;
		$this->entityType	= $entityType;
		$this->association 	= $association;
		$this->typeProvider = $provider;

	}

	/**
	 * Generate the definition for the GraphQL field
	 *
	 * @return array
	 */
	public function getDefinition(){

		// Resolve the types with the provider
		$outputType = $this->typeProvider->getType($this->entityType);
		$filterType = $this->typeProvider->getFilterType($this->entityType);

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

				$targetToSource = $this->association['sourceToTargetKeyColumns'];

				$sourceColumn = $this->association['fieldName'];

				if($targetToSource != null)
					$sourceColumn = array_keys($targetToSource)[0];

				// Get the refenced it without triggering the doctrine auto hydration
				// This is why we need the GraphEntity to act as a proxy.
				$identifier = (is_object($parent) ? $parent->getDataValue($sourceColumn) : $parent[$sourceColumn]);

				if($identifier != null) {

					// Initialize the buffer, if initialized use the existing one
					$buffer = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $this->entityType);

					$buffer->add($identifier);

					// GraphQLPHP will call the deferred resolvers as needed.
					return new \GraphQL\Deferred(function() use ($buffer, $identifier, $args) {

						// Populate the buffer with the loaded data
						$this->loadBuffered($args, $identifier);

						$em = $this->typeProvider->getManager();

						$graphHydrator = new GraphHydrator($em);

						$result = null;

						// Retrieve the result from the buffer
						$data = $buffer->result($identifier);

						// Create a GraphEntity and Hydrate the doctrine object
						if ($data !== null)
							$result = $graphHydrator->hydrate($data, $this->entityType);

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


		$mappedBy	= $this->association['joinColumns'][0]['referencedColumnName'];

		$entityType = $this->entityType;

		// Fetch the buffer associated with this type
		$buffer 	  = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $entityType);

		// Have we already loaded to data, if not proceed
		if(!$buffer->isLoaded()) {

			// Create a query using the arguments passed in the query
			$queryBuilder = $entityType::getRepository()->createQueryBuilder('e');

			$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $mappedBy, ':' . $mappedBy));
			$queryBuilder->setParameter($mappedBy, $buffer->get());

			foreach ($args as $name => $values) {

				$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $name, ':' . $name));
				$queryBuilder->setParameter($name, $values);

			}

			$query = $queryBuilder->getQuery();
			$query->setHint(Query::HINT_INCLUDE_META_COLUMNS, true); // Include associations

			// Use array hydration only. GraphHydrator will handle hydration of doctrine objects.
			$results = $query->getResult(Query::HYDRATE_ARRAY);

			$resultsLoaded = array();

			// Group the results by the parent entity
			foreach ($results as $result) {

				// TODO: needs to support composite identifiers
				$parentId = $result[$mappedBy];

				$resultsLoaded[$parentId] = $result;

			}

			// Load the buffer with the results.
			$buffer->load($resultsLoaded);

		}

	}


}