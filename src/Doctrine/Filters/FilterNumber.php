<?php

namespace RateHub\GraphQL\Doctrine\Filters;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

class FilterNumber
{

	const NAME = 'filternumber';

	/**
	 * @param $dataType - Can be one of many different number types, int, bigint
	 * @return InputObjectType
	 */
	public static function getType($dataType){

		$name = self::NAME . $dataType->name;

		$filterFields = array(
			array(
				'name' => 'in',
				'type' => Type::listOf($dataType)
			),
			array(
				'name' => 'equals',
				'type' => $dataType
			),
			array(
				'name' => 'greater',
				'type' => $dataType
			),
			array(
				'name' => 'less',
				'type' => $dataType
			),
			array(
				'name' => 'greaterOrEquals',
				'type' => $dataType
			),
			array(
				'name' => 'lessOrEquals',
				'type' => $dataType
			)
		);

		return new InputObjectType(array('name' => $name, 'fields' => $filterFields));

	}


}