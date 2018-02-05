<?php

namespace RateHub\GraphQL\Doctrine;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\InputObjectType;

/**
 * Class SortField
 *
 *
 * @package RateHub\GraphQL\Doctrine
 */
class GraphSortField {

	const NAME = 'SortField';

	/**
	 * @var string Name of the field to order by
	 */
	public $field;

	/**
	 * @var string order: asc or desc.
	 */
	public $order;

	/**
	 * Return the GraphQL type for this class
	 *
	 * @return ObjectType
	 */
	public static function getType() {

		$typeFields = array(
			array(
				'name' => 'field',
				'type' => Type::string()
			),
			array(
				'name' => 'order',
				'type' => Type::string()
			)
		);

		return new InputObjectType(array('name' => self::NAME, 'fields' => $typeFields));

	}

}