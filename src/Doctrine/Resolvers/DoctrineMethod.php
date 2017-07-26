<?php

namespace RateHub\GraphQL\Doctrine\Resolvers;

use RateHub\GraphQL\Interfaces\IGraphQLResolver;

/**
 * Class DoctrineField
 *
 * Basic field resolver. Maps a GraphQL property to a Doctrine Entity's method
 *
 * @package App\Api\GraphQL\Resolvers
 *
 */
class DoctrineMethod implements IGraphQLResolver {

	/**
	 * @var String	Field name as it will be output in GraphQL
	 */
	private $name;

	/**
	 * @var String	Return type for the field (method)
	 */
	private $type;

	/**
	 * @var String  The method name on the Doctrine entity
	 */
	private $method;

	/**
	 * @var String	The description for the field. The description is used in
	 * 				the Introspective api
	 */
	private $description;


	/**
	 * DoctrineMethod constructor.
	 * @param $name			String 	Name of the property as it should appear in the GraphQL api
	 * @param $type			String  The value type for the returned values
	 * @param $description	String 	The description as it should show up in the Introspection api
	 * @param $method		String 	The method that should be called each time this property is access via GraphQL
	 */
	public function __construct($name, $type, $description, $method){

		$this->name 		= $name;
		$this->type 		= $type;
		$this->method 		= $method;
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
		 * Build return the definition.
		 */
		return array(
			'name' => $this->name,
			'type' => $this->type,
			'description' => $this->description,
			'resolve' => function($value, $args, $context, $info){

				// Call the method on the GraphEntity's related doctrine object.
				return $value->getByMethod($this->method);

			}
		);

	}

}