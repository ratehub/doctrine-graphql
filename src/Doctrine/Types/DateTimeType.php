<?php

namespace RateHub\GraphQL\Doctrine\Types;

use \GraphQL\Type\Definition\ScalarType;

/**
 * Class HstoreType
 * @package App\Api\GraphQL\Types
 */
class DateTimeType extends ScalarType {


	// Note: name can be omitted. In this case it will be inferred from class name
	// (suffix "Type" will be dropped)
	public $name = 'datetime';

	/**
	 * Serializes an internal value to include in a response.
	 *
	 * @param string $value
	 * @return string
	 */
	public function serialize($value)
	{

		// Assuming internal representation of email is always correct:
		if($value !== null)
			return $value->getTimestamp();
		return $value;

	}

	/**
	 * Parses an externally provided value (query variable) to use as an input
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function parseValue($value)
	{

		if($value !== null){
			$date = new \DateTime();
			return $date->setTimestamp($value);
		}
		return $value;

	}

	/**
	 * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
	 *
	 * E.g.
	 * {
	 *   user(email: "user@example.com")
	 * }
	 *
	 * @param \GraphQL\Language\AST\Node $valueNode
	 * @return string
	 * @throws Error
	 */
	public function parseLiteral($valueNode)
	{

		if($valueNode->value !== null) {
			$date = new \DateTime();
			return $date->setTimestamp($valueNode->value);
		}
		return $valueNode->value;

	}

}