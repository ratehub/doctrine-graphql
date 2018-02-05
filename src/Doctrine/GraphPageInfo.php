<?php

namespace RateHub\GraphQL\Doctrine;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;

/**
 * Class GraphPageInfo
 *
 * Provides resolvers with pagination support. Typical scenarios
 * 		Top level query returning multiple rows
 * 		To-Many assocation returning multiple rows
 *
 * Provides necessary graphql types and methods to add pagination
 * support to queries.
 *
 * @package RateHub\GraphQL\Doctrine
 */
class GraphPageInfo {

	const NAME = 'PageInfo';

	/**
	 * @var string Base 64 encoded cursor for a record. Cursor is based on primary keys.
	 */
	public $cursor;

	/**
	 * @var boolean Whether the current list has more records that are yet to be returned
	 */
	public $hasMore;

	/**
	 * Return the GraphQL type for this class
	 *
	 * @return ObjectType
	 */
	public static function getType(){

		$pageFields = array(
			array(
				'name' => 'cursor',
				'type' => Type::string()
			),
			array(
				'name' => 'hasMore',
				'type' => Type::boolean()
			)
		);

		return new ObjectType(array('name' => self::NAME, 'fields' => $pageFields));

	}

	/**
	 * Return top level query filters (arguments) that are available when pagination is used.
	 *
	 * @return array
	 */
	public static function getQueryFilters($provider){

		$filterFields = array();

		$filterFields['first']  = array('name' => 'first',  'type' => Type::int());
		$filterFields['after']  = array('name' => 'after',  'type' => Type::string());
		$filterFields['offset'] = array('name' => 'offset', 'type' => Type::int());

		$sortFieldType = $provider->getType(GraphSortField::NAME);

		$filterFields['sort'] = array('name' => 'sort', 'type' => Type::listOf($sortFieldType));

		return $filterFields;

	}

	/**
	 * Return assocation filters (arguments) that are available when pagination is used.
	 *
	 * @return array
	 */
	public static function getFilters(){

		$filterFields = array();

		$filterFields['first']  = array('name' => 'first', 'type' => Type::int());

		return $filterFields;

	}

	/**
	 * Add pagination DQL clauses to the current query based on query filters (arguments)
	 * Supported arguments:
	 *   first    Integer representing the maximum number of records to return
	 *   offset   Integer representing the starting record offset
	 *   after    String representing the cursor of the record to start from.
	 *
	 * Offset and after cannot be used for the same filter. Offset is given priority if both are passed
	 *
	 * @param $queryBuilder
	 * @param $identifiers The identifier columns for the current entity
	 * @param $args	List of all arguments.
	 * @return mixed Returns the arguments list with any pagination arguments removed
	 */
	public static function paginateQuery($queryBuilder, $identifiers, $args){

		// Handle the first argument.
		if(array_key_exists('first', $args)){

			$maxResults = $args['first'];
			$queryBuilder->setMaxResults($maxResults + 1);

			// Remove argument once used
			unset($args['first']);

		}

		$hasOffset = false;

		// Handle the offset argument.
		if(array_key_exists('offset', $args)){

			$queryBuilder->setFirstResult($args['offset']);

			// Remove argument once used
			unset($args['offset']);

			$hasOffset = true;

		}

		// Handle the after argument
		if(array_key_exists('after', $args)){

			// Give priority to offset
			if(!$hasOffset)
				static::addAfter($queryBuilder, $identifiers, $args['after']);

			// Remove argument once used
			unset($args['after']);

		}

		return $args;

	}

	/**
	 * @param $queryBuilder
	 * @param $identifiers
	 * @param $args
	 */
	public static function sortQuery($queryBuilder, $identifiers, $args){

		$hasOrderBy = false;

		// Handle the first argument.
		if(array_key_exists('sort', $args)){

			foreach($args['sort'] as $sortField){

				$sortDirection = strtolower((isset($sortField['order']) ? $sortField['order'] : 'asc' ));

				if(!($sortDirection == 'asc' || $sortDirection == 'desc'))
					$sortDirection = 'asc';

				$queryBuilder->addOrderBy('e.' . $sortField['field'], $sortField['order']);

			}

			// Remove argument once used
			unset($args['sort']);

			$hasOrderBy = true;

		}

		if(!$hasOrderBy){

			// Add Order By
			foreach($identifiers as $id) {
				$queryBuilder->addOrderBy('e.' . $id, 'ASC');
			}

		}

		return $args;

	}

	/**
	 * Adds the necessary where clauses if the after argument is passed. With a single primary key the
	 * where clause is simple:
	 *
	 * 1 key
	 * e.key1 > value1
	 *
	 * It get's slightly more complicated with composite keys. This method handles generating the boolean logic.
	 * Postgres supports (compkey1, compkey2, compkey3) > (compValue1, compValue2, compValue3) syntax but the doctrine
	 * query parser doesn't. This is more generic and actually has a lower explain cost. Could see about adding
	 * support to the parser, certainly more readable.
	 *
	 * 2 composite keys
	 * e.compkey1 >= 'compValue1' AND (e.compkey1 > 'compValue1' OR e.compkey2 > 'compValue2')
	 *
	 * 3 composite keys
	 * compkey1 >= 'compValue1' AND (e.compkey1 > compValue1 OR (e.compkey2 >= compValue2 AND (e.compkey2 > compValue2 OR e.compkey3 > compValue3)))
     *
	 * @param $queryBuilder
	 * @param $identifiers	Identifier columns for the current entity
	 * @param $values		Cursor passed as a query argument
	 */
	public static function addAfter($queryBuilder, $identifiers, $values){

		$after = explode(':' , base64_decode($values));

		$identifierString = static::generateQuery($identifiers, $after);

		$queryBuilder->andWhere($identifierString);

	}

	/**
	 * Recursive method used by addAfter that generates the boolean logic for a > comparison on a single or composite
	 * primary key.
	 *
	 * @param $identifiers	Identifier columns for the current entity
	 * @param $values		Identifier column values for the cursor
	 * @return string		Returns the where statement
	 */
	public static function generateQuery($identifiers, $values){

		$nextIdentifiers 	= array_slice( $identifiers, 1, count($values) - 1 );
		$nextValues 		= array_slice( $values, 1, count($values) - 1 );

		// If there are additional identifiers, need an additional recursive call
		if(count($nextIdentifiers) !== 0){
			$identifierString = 'e.' . $identifiers[0] . ' >= \'' . $values[0] . '\' AND ( e.' . $identifiers[0]  . ' > \'' . $values[0] . '\' OR (' . static::generateQuery($nextIdentifiers, $nextValues) . '))';

		// No additional identifiers, last statement
		}else{
			$identifierString = 'e.' . $identifiers[0] . ' > \'' . $values[0] . '\'';
		}

		return $identifierString;

	}

}