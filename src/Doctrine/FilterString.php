<?php

namespace RateHub\GraphQL\Doctrine;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\InputObjectType;

class FilterString
{

	const NAME = 'filterstring';

	public static function getType(){

		$filterFields = array(
			array(
				'name' => 'contains',
				'type' =>  Type::string()
			),
			array(
				'name' => 'equals',
				'type' => Type::string()
			),
			array(
				'name' => 'startsWith',
				'type' => Type::string()
			),
			array(
				'name' => 'endsWith',
				'type' => Type::string()
			),
			array(
				'name' => 'in',
				'type' => Type::listOf(Type::string())
			)
		);

		return new InputObjectType(array('name' => self::NAME, 'fields' => $filterFields));

	}


}