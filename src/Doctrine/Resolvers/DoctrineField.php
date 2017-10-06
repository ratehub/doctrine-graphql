<?php

namespace RateHub\GraphQL\Doctrine\Resolvers;

use RateHub\GraphQL\Interfaces\IGraphQLResolver;

/**
 * Class DoctrineField
 *
 * Basic field resolver
 *
 * @package App\Api\GraphQL\Resolvers
 *
 */
class DoctrineField implements IGraphQLResolver {

	/**
	 * @var	String	Field name as it will be output in GraphQL
	 */
	private $name;

	/**
	 * @var String	Return type for the field
	 */
	private $type;

	/**
	 * @var String	The description for the field. The description is used in
	 * 				the Introspective api
	 */
	private $description;

	/**
	 * DoctrineField constructor.
	 * @param $name			String	Field name as shown in GraphQL
	 * @param $type			Return type for the field
	 * @param $description	The description for the field. The description is used in
	 * 						the Introspective api
	 */
	public function __construct($name, $type, $description){

		$this->name 		= $name;
		$this->type 		= $type;
		$this->description 	= $description;

	}

	/**
	 * Generate the definition for the GraphQL field
	 *
	 * @return array
	 */
	public function getDefinition(){

		/**
		 * Value will be the parent object when it's passed in.
		 */
		return array(
			'name' => $this->name,
			'type' => $this->type,
			'description' => $this->description,
			'resolve' => function($value, $args, $context, $info){

				if(is_array($value))
					return $value[$this->name];

				$fieldValue = $value->get($this->name);

				if($this->type->name !== 'array'
                    && $this->type->name !== 'json'
                    && $this->type->name !== 'hstore'
                    && is_array($fieldValue))
					return json_encode($fieldValue);

				return $fieldValue;

			}

		);

	}

}