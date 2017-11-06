<?php

namespace RateHub\GraphQL\Doctrine;

use GraphQL\Type\Definition\InputObjectType;

class FilterDateTimeBetween
{

	const NAME = 'filterdatetimebetween';

	public static function getType($dateType){

		$filterFields = array(
			array(
				'name' => 'from',
				'type' => $dateType
			),
			array(
				'name' => 'to',
				'type' => $dateType
			)
		);

		return new InputObjectType(array('name' => self::NAME, 'fields' => $filterFields));

	}


}