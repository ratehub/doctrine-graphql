<?php

namespace RateHub\GraphQL\Doctrine\Resolvers;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
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
			$args[$filter->name] = array('name' => $filter['name'], 'type' => $filter['type']);
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

				// Retrieve the id from the parent object.
				// Id can be a single field or a composite.
				$identifier = null;

				if(is_object($parent)){
					$identifierFields = $this->typeProvider->getTypeIdentifiers($parent->getObject()->className);
				}else{
					$identifierFields = ['id'];
				}

				$identifier = [];

				foreach($identifierFields as $field){
					$identifier[$field] = $parent->get($field);
				}

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

					$dataList = $buffer->result(implode(':', array_values($identifier)));

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

		if($mappedBy === null){
			$mappedBy = $this->association['inversedBy'];
		}

		if($this->association['isOwningSide']){
			//$association = $type->getAssociationMapping($mappedBy);
			$association = $type->getAssociationMapping($this->association['inversedBy']);
		}else{
			$association = $this->association;
		}


		// Get the target identifiers, will need them for order and pagination
		$targetIdentifiers = $this->typeProvider->getTypeIdentifiers($association['targetEntity']);
		$sourceIdentifiers = $this->typeProvider->getTypeIdentifiers($association['sourceEntity']);


		$entityType = $this->entityType;

		// Fetch the buffer associated with this type
		$buffer = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $entityType);

		// Have we already loaded to data, if not proceed
		if(!$buffer->isLoaded()) {

			// Create a query using the arguments passed in the query
			$queryBuilder = $entityType::getRepository()->createQueryBuilder('e');

			if (isset($association['joinColumns'])) {

				$joinCount = 0;

				foreach ($association['joinColumns'] as $col) {

					$alias = 'e' . $joinCount;
					$queryBuilder->addSelect($alias)->leftJoin('e.' . $mappedBy, $alias);

					$parentIds = $buffer->get();

					foreach ($parentIds as $parentId) {

						foreach ($targetIdentifiers as $id) {
							$queryBuilder->andWhere($queryBuilder->expr()->in($alias . '.' . $id, ':' . $id));
							$queryBuilder->setParameter($id, $parentId[$id]);
						}

					}

					$joinCount++;

				}

				$identifiers = $this->typeProvider->getTypeIdentifiers($this->entityType);

			}else if(isset($association['joinTable'])) {

				$joinCount = 0;

				$alias = 'e' . $joinCount;
				$queryBuilder->addSelect($alias)->leftJoin('e.' . $mappedBy, $alias);

				$parentIds = $buffer->get();

				$idField = null;

				if($this->association['isOwningSide']) {
					if (count($targetIdentifiers) > 1) {
						$idField = 'id';
					} else {
						$idField = $targetIdentifiers[0];
					}
				}else{
					if (count($sourceIdentifiers) > 1) {
						$idField = 'id';
					} else {
						$idField = $sourceIdentifiers[0];
					}
				}

				$queryBuilder->andWhere($queryBuilder->expr()->in($alias . '.' . $idField, ':id' ));
				$queryBuilder->setParameter('id', $parentIds);

				$joinCount++;

				$identifiers = $this->typeProvider->getTypeIdentifiers($this->entityType);

			}else {

				$parentIds = $buffer->get();

				$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $mappedBy, ':' . $mappedBy));
				$queryBuilder->setParameter($mappedBy, $parentIds);

				$identifiers = $this->typeProvider->getTypeIdentifiers($this->entityType);

			}

			// Add pagination DQL clauses to the query. In this case we'll
			// only be adding the order by statement
			$args = GraphPageInfo::paginateQuery($queryBuilder, $identifiers, $args);

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

				$parents = null;

				if(isset($result[$mappedBy])){

					$parents = $result[$mappedBy];

				}else {

					if ($type->isAssociationWithSingleJoinColumn($mappedBy)){
						$columnName = $type->getSingleAssociationJoinColumnName($mappedBy);
						$parents = $result[$columnName];
					}

				}

				if($parents !== null){

					// Parents can be a single or multiple records
					if($association['type'] === ClassMetadataInfo::MANY_TO_ONE || $association['type'] === ClassMetadataInfo::ONE_TO_ONE){

						$identifierValues = [];

						foreach ($targetIdentifiers as $identifier) {
							array_push($identifierValues, $parents[$identifier]);
						}

						$parentId = implode(':', $identifierValues);

						if (!isset($resultsLoaded[$parentId]))
							$resultsLoaded[$parentId] = array();

						array_push($resultsLoaded[$parentId], $result);

					}else{

						// Many to many relationships will generate arrays
						if(is_array($parents)){

							foreach($parents as $parent) {

								$identifierValues = [];

								if($this->association['isOwningSide']) {
									foreach ($targetIdentifiers as $identifier) {
										array_push($identifierValues, $parent[$identifier]);
									}
								}else{
									foreach ($sourceIdentifiers as $identifier) {
										array_push($identifierValues, $parent[$identifier]);
									}
								}

								$parentId = implode(':', $identifierValues);

								if (!isset($resultsLoaded[$parentId]))
									$resultsLoaded[$parentId] = array();

								array_push($resultsLoaded[$parentId], $result);

							}

						// One to many will generate a single value
						}else{

							$parentId = $parents;

							if (!isset($resultsLoaded[$parentId]))
								$resultsLoaded[$parentId] = array();

							array_push($resultsLoaded[$parentId], $result);

						}


					}

				}

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

		if(!$mappedBy)
			$mappedBy = $this->association['inversedBy'];

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

				if (isset($association['joinColumns'])) {

					$joinCount = 0;

					foreach ($association['joinColumns'] as $col) {

						$targetTable = $association['targetEntity'];

						$alias = 'e' . $joinCount;
						$queryBuilder->addSelect($alias)->leftJoin('e.' . $mappedBy, $alias);

						$identifiers = $buffer->get();

						foreach($identifiers as $identifier){

							foreach($targetIdentifiers as $id) {
								$queryBuilder->andWhere($queryBuilder->expr()->in($alias . '.' . $id, ':' . $id));
								$queryBuilder->setParameter($id, $identifier[$id]);
							}

						}

						$joinCount++;

					}

					$identifiers = $this->typeProvider->getTypeIdentifiers($this->entityType);

				}else {

					$queryBuilder->andWhere($queryBuilder->expr()->eq('e.' . $mappedBy, ':' . $mappedBy));
					$queryBuilder->setParameter($mappedBy, $parentId);

				}

				// Add limit and order DQL statements
				$filteredArgs = GraphPageInfo::paginateQuery($queryBuilder, $identifiers, $args);

				// Add additional where clauses based on query arguments
				foreach ($filteredArgs as $name => $values) {

					$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $name, ':' . $name));
					$queryBuilder->setParameter($name, $values);

				}

				$query = $queryBuilder->getQuery();

				$query->setHint(Query::HINT_INCLUDE_META_COLUMNS, true); // Include associations

				// Use array hydration only. GraphHydrator will handle hydration of doctrine objects.
				$results = $query->getResult(Query::HYDRATE_ARRAY);

				$parentIdString = implode(':', array_values($parentId));

				$resultsLoaded[$parentIdString] = $results;

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