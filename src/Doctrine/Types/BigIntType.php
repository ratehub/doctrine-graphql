<?php

namespace RateHub\GraphQL\Doctrine\Types;

use \GraphQL\Type\Definition\ScalarType;

/**
 * Class BigInt Type
 *
 * BigInt's need to be handled as strings
 *
 * https://github.com/graphql/graphql-js/issues/292
 *
 * @package App\Api\GraphQL\Types
 */
class BigIntType extends ScalarType {


	// Note: name can be omitted. In this case it will be inferred from class name
	// (suffix "Type" will be dropped)
	public $name = 'bigint';

	/**
	 * Serializes an internal value to include in a response.
	 *
	 * @param string $value
	 * @return string
	 */
	public function serialize($value)
	{

		// Assuming internal representation of email is always correct:
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

		return $valueNode->value;

	}

}