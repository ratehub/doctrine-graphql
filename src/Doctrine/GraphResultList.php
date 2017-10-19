<?php

namespace RateHub\GraphQL\Doctrine;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;

/**
 * Class GraphResultList
 *
 * Acts as a wrapper for a list of records, provides some additional metadata
 * such as:
 *
 * totalCount  Number of rows returned
 * items       The list of items
 * pageInfo	   Pagination info such as hasMore and cursor.
 *
 * @package RateHub\GraphQL\Doctrine
 */
class GraphResultList {

	/**
	 * @var int	Number of rows returned
	 */
	public $totalCount;

	/**
	 * @var array List of entities returned
	 */
	public $items;

	/**
	 * @var GraphPageInfo  Pagination info
	 */
	public $pageInfo;

	/**
	 * GraphResultList constructor.
	 *
	 * Constructor handles generation and hydration of the list and
	 * setting of the meta information for the returned list
	 *
	 * @param $dataList  		List of entities (retrieved via HYDRATE_ARRAY)
	 * @param $args				List of arguments passed to the graphql query
	 * @param $graphHydrator	Instance of the graphHydrator
	 * @param $typeProvider		The typeProvider for the query
	 * @param $entityType		The Type of entity being returned.
	 */
	public function __construct($dataList, $args, $graphHydrator, $typeProvider, $doctrineClass, $graphName){

		$hydratedResult = array();

		$cnt = 0;

		$maxResults = null;
		$cursor 	= null;
		$hasMore 	= false;

		// Determine the max results for the list
		if(isset($args['first']))
			$maxResults = $args['first'];

		// Make sure we have some actual data
		if($dataList !== null) {

			// Use array hydration only. GraphHydrator will handle hydration of doctrine objects.
			foreach ($dataList as $result) {

				// If we haven't hit the maximum number of rows
				if ($maxResults === null || $cnt < $maxResults) {

					// Generate the GraphEntities and hydrate the doctrine objects.
					array_push($hydratedResult, $graphHydrator->hydrate($result, $doctrineClass));

					// Generate cursor using identifier columns
					$identifiers = $typeProvider->getTypeIdentifiers($graphName);

					$cursor = static::generateCursor($result, $identifiers, $typeProvider, $graphName);

					$cnt++;

				// We've hit the maximum number of rows. Set hasMore = true so we know to query for more
				// when needed.
				} else {

					$hasMore = true;

				}

			}

		}

		// Update the instance with details
		$this->totalCount 			= $cnt;
		$this->items 				= $hydratedResult;
		$this->pageInfo 			= new GraphPageInfo();
		$this->pageInfo->cursor 	= $cursor;
		$this->pageInfo->hasMore 	= $hasMore;

	}

	/**
	 * Create a cursor for the entity, given it's identifiers and type.
	 * Cursors support single and composite primary keys
	 *
	 * @param $result
	 * @param $identifiers
	 * @param $typeProvider
	 * @param $entityType
	 * @return null|string
	 */
	public static function generateCursor($result, $identifiers, $typeProvider, $graphName){

		$cursorItems = array();

		$cursor = null;

		/**
		 * Loop through the identifiers to create a list of identifier values
		 */
		foreach ($identifiers as $id) {

			$type = $typeProvider->_doctrineMetadata[$graphName];

			$associations = $type->getAssociationMappings();

			// The identifier is an association. We need to find the primary key
			// column on the related entity to get it's identifier value
			if (array_key_exists($id, $associations)) {

				$association = $type->getAssociationMapping($id);

				// Target entity's association column
				$columnName = $association['joinColumns'][0]['name'];

				array_push($cursorItems, $result[$columnName]);

			// Identifier is not an association so we can simply get the value.
			} else {

				array_push($cursorItems, $result[$id]);

			}

		}

		// If we have values then generate and return cursor
		if(count($cursorItems) > 0)
			$cursor = base64_encode(implode(':', $cursorItems));

		return $cursor;

	}

	/**
	 * Returns the GraphQL type instance representing this class.
	 *
	 * @param $name
	 * @param $listType
	 * @param $pageInfoType
	 * @return ObjectType
	 */
	public static function getType($name, $listType, $pageInfoType){

		$resultFields = array();

		array_push($resultFields, array(
			'name' => 'totalCount',
			'type' => Type::int()
		));

		array_push($resultFields, array(
			'name' => 'items',
			'type' => Type::listOf($listType)
		));

		array_push($resultFields, array(
			'name' => 'pageInfo',
			'type' => $pageInfoType
		));

		return new ObjectType(array('name' => $name, 'fields' => $resultFields));

	}

}