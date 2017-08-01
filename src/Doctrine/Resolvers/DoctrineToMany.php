<?php

namespace RateHub\GraphQL\Doctrine\Resolvers;

use RateHub\GraphQL\Interfaces\IGraphQLResolver;

use RateHub\GraphQL\Doctrine\DoctrineDeferredBuffer;
use RateHub\GraphQL\Doctrine\GraphHydrator;
use RateHub\GraphQL\Doctrine\GraphResultList;
use RateHub\GraphQL\Doctrine\GraphPageInfo;

use Doctrine\ORM\Query;


/**
 * Class DoctrineToMany
 *
 * Resolver handles N-to-Many doctrine relationships.
 * Leverages the DoctrineDeferredBuffer and GraphDeferred to delay querying for the new data until
 * all fields have been processed at which point GraphQLPHP process the deferred fields. During
 * the deferred field resolution the buffer is populated 'once' with the required data.
 * Reduces the number of queries from N to 1.
 *
 * @package App\Api\GraphQL\Resolvers
 */
class DoctrineToMany implements IGraphQLResolver {

	/**
	 * @var String	Field name as it will be output in GraphQL
	 */
	private $name;

	/**
	 * @var String	The description for the field. The description is used in
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
	 * DoctrineToMany constructor.
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
		$filterType 	= $this->typeProvider->getFilterType($this->entityType);

		$args = array();

		$outputType = $this->getOutputType();

		foreach(GraphPageInfo::getFilters() as $filter){
			$args[$filter->name] = array('name' => $filter->name, 'type' => $filter->getType());
		}

		// Generate the argument definition based on the filter type.
		// No type means the type has no additional inputs
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

				// TODO: verify composite ids
				$identifier = (is_object($parent) ? $parent->get('id') : $parent['id']);

				// Initialize the buffer, if initialized use the existing one
				$buffer = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $this->entityType);

				// Need to defer execution. Add parent ids so that we can query
				// all at once instead of per record
				$buffer->add($identifier);

				// GraphQLPHP will call the deferred resolvers as needed.
				return new \GraphQL\Deferred(function () use ($buffer, $identifier, $args) {

					// Populate the buffer with the loaded data
					// For to-Many relationships we can only do bulk queries
					// if there are no limits on the number of results. Without
					// data optimizations there is no way to get a list of
					// multiple parent entities with a to-many relation containing a limit.
					// Example:
					// Parent 1
					//   Association (first 10)
					// Parent 2
					//   Association (first 10)
					// Even doing a single query for Association with limit 20 does
					// not guarantee the correct result. Suppose Parent 1 has over 20
					// assocations, Parent 2 would appear to have none.
					if($this->doBulkLoad($args)) {

						$this->loadBufferedInBulk($args, $identifier);

					}else{

						$this->loadBuffered($args, $identifier);

					}

					$em = $this->typeProvider->getManager();

					$graphHydrator = new GraphHydrator($em);

					$dataList = $buffer->result($identifier);

					// Process the data results and return a pagination result list.
					// Result list contains a list of GraphEntities to be traversed
					// during resolve operations
					$resultList = new GraphResultList($dataList, $args, $graphHydrator, $this->typeProvider, $this->entityType);

					return $resultList;

				});

			}

		);

	}

	/**
	 * Determine if we should perform the query for this association in bulk
	 * or not.
	 *
	 * @param $args List of arguments passed to this part of the query
	 * @return bool Whether we should perform the query in bulk or not
	 */
	public function doBulkLoad($args){

		if(isset($args['first']))
			return false;

		return true;
	}

	/**
	 * Load the data for this association as a single query. Will return all assocation rows.
	 *
	 * @param $args		   The filter arguments for this entity passed via the GraphQL query
	 * @param $identifier  Identifier for the parent object as this is an N-to-Many relationship
	 */
	public function loadBufferedInBulk($args, $identifier){

		// Fetch the buffer associated with this type
		$type = $this->typeProvider->_doctrineMetadata[$this->entityType];

		// Query name
		$mappedBy	= $this->association['mappedBy'];

		$association = $type->getAssociationMapping($mappedBy);

		// Target entity's association column
		$columnName = $association['joinColumns'][0]['name'];

		// Get the target identifiers, will need them for order and pagination
		$targetIdentifiers = $this->typeProvider->getTypeIdentifiers($association['targetEntity']);

		$entityType = $this->entityType;

		// Fetch the buffer associated with this type
		$buffer 	  = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $entityType);

		// Have we already loaded to data, if not proceed
		if(!$buffer->isLoaded()) {

			// Create a query using the arguments passed in the query
			$queryBuilder = $entityType::getRepository()->createQueryBuilder('e');

			$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $mappedBy, ':' . $mappedBy));
			$queryBuilder->setParameter($mappedBy, $buffer->get());

			// Add pagination DQL clauses to the query. In this case we'll
			// only be adding the order by statement
			$args = GraphPageInfo::paginateQuery($queryBuilder, $targetIdentifiers, $args);

			// Add additional where statements based on passed arguments.
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
				$parentId = $result[$columnName];

				if (!isset($resultsLoaded[$parentId]))
					$resultsLoaded[$parentId] = array();

				array_push($resultsLoaded[$parentId], $result);

			}

			// Load the buffer with the results.
			$buffer->load($resultsLoaded);

		}

	}

	/**
	 * Load the data for this association as multiple queries with limit support.
	 * Careful using this method with a heavily nested query. The number of SQL Queries
	 * executed can balloon in very quickly.
	 *
	 * @param $args
	 * @param $identifier
	 */
	public function loadBuffered($args, $identifier){

		// Fetch the buffer associated with this type
		$type = $this->typeProvider->_doctrineMetadata[$this->entityType];

		// Query name
		$mappedBy	= $this->association['mappedBy'];

		$association = $type->getAssociationMapping($mappedBy);

		// Target entity's association column
		$columnName = $association['joinColumns'][0]['name'];

		// Get the target identifiers, will need them for order and pagination
		$targetIdentifiers = $this->typeProvider->getTypeIdentifiers($association['targetEntity']);

		$entityType = $this->entityType;

		// Fetch the buffer associated with this type
		$buffer 	  = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $entityType);

		// Have we already loaded to data, if not proceed
		if(!$buffer->isLoaded()) {

			$resultsLoaded = array();

			// Loop through each parent id and perform queries for each to
			// retrieve association data.
			foreach($buffer->get() as $parentId){

				// Create a query using the arguments passed in the query
				$queryBuilder = $entityType::getRepository()->createQueryBuilder('e');

				$queryBuilder->andWhere($queryBuilder->expr()->eq('e.' . $mappedBy, ':' . $mappedBy));
				$queryBuilder->setParameter($mappedBy, $parentId);

				// Add limit and order DQL statements
				$filteredArgs = GraphPageInfo::paginateQuery($queryBuilder, $targetIdentifiers, $args);

				// Add additional where clauses based on query arguments
				foreach ($filteredArgs as $name => $values) {

					$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $name, ':' . $name));
					$queryBuilder->setParameter($name, $values);

				}

				$query = $queryBuilder->getQuery();

				$query->setHint(Query::HINT_INCLUDE_META_COLUMNS, true); // Include associations

				// Use array hydration only. GraphHydrator will handle hydration of doctrine objects.
				$results = $query->getResult(Query::HYDRATE_ARRAY);

				$resultsLoaded[$parentId] = $results;

			}

			// Load the buffer with the results.
			$buffer->load($resultsLoaded);

		}

	}

	/**
	 * Get the output type for the to many relationship. Because it involves a list
	 * we'll add pagination support
	 *
	 * @return null|void
	 */
	public function getOutputType(){

		// Retrieve the required types for the result list
		$listType 		= $this->typeProvider->getType($this->entityType);
		$pageInfoType 	= $this->typeProvider->getType(GraphPageInfo::NAME);

		// Define the name
		$outputTypeName = $this->name . '__List';

		// Multiple associations can be related to the same object resulting
		// in the same output type. Only need one instance instantiated.
		if($this->typeProvider->getType($outputTypeName) === null){

			$outputType = GraphResultList::getType($outputTypeName, $listType, $pageInfoType);

			$this->typeProvider->addType($outputTypeName, $outputType);

			// Already been defined
		}else{

			$outputType =  $this->typeProvider->getType($outputTypeName);

		}

		return $outputType;

	}

}