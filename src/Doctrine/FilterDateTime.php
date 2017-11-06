<?php

namespace RateHub\GraphQL\Doctrine;

use GraphQL\Type\Definition\InputObjectType;

class FilterDateTime
{

	const NAME = 'filterdatetime';

	public static function getType($dateType, $dateBetweenType){

		$filterFields = array(
			array(
				'name' => 'equals',
				'type' => $dateType
			),
			array(
				'name' => 'greater',
				'type' => $dateType
			),
			array(
				'name' => 'less',
				'type' => $dateType
			),
			array(
				'name' => 'greaterOrEquals',
				'type' => $dateType
			),
			array(
				'name' => 'lessOrEquals',
				'type' => $dateType
			),
			array(
				'name' => 'between',
				'type' => $dateBetweenType
			)

		);

		return new InputObjectType(array('name' => self::NAME, 'fields' => $filterFields));

	}


}