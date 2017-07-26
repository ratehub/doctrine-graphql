<?php

namespace RateHub\GraphQL\Doctrine\Types;

use \GraphQL\Type\Definition\ScalarType;

/**
 * Class JsonType
 * @package App\Api\GraphQL\Types
 */
class JsonType extends ScalarType{

	// Note: name can be omitted. In this case it will be inferred from class name
	// (suffix "Type" will be dropped)
	public $name = 'json';

	/**
	 * Serializes an internal value to include in a response.
	 *
	 * @param string $value
	 * @return string
	 */
	public function serialize($value)
	{

		// Assuming internal representation of data is always correct
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

		// Json Array
		//return $this->parseNodeAsTree($valueNode);

		$result = array();

		return $this->parseNodeAsList($result, '', $valueNode);

	}

	private function parseNodeAsTree($valueNode){

		$result = array();

		foreach($valueNode->fields as $childNode){

			if($childNode->kind === 'ObjectField') {

				if($childNode->value->kind === "ObjectValue") {

					$result[$childNode->name->value] = $this->parseNodeAsTree($childNode->value);

				}else{

					$result[$childNode->name->value] = $childNode->value->value;

				}

			}

		}

		return $result;

	}

	/**
	 *
	 * Recursively parse through the parameter and return a flat list of path's to values
	 *
	 * @param $result
	 * @param $currentPath
	 * @param $valueNode
	 * @return mixed
	 */
	private function parseNodeAsList(&$result, $currentPath, $valueNode){

		foreach($valueNode->fields as $childNode){

			if($childNode->kind === 'ObjectField') {

				$subPath = $currentPath;

				if($subPath !== '')
					$subPath .= '.';

				$subPath .= $childNode->name->value;

				if($childNode->value->kind === "ObjectValue") {

					$this->parseNodeAsList($result, $subPath, $childNode->value);

				}else{

					$result[$subPath] = array(
						'value' => $this->mapValue($childNode->value->kind, $childNode->value->value),
						'type' => $this->mapType($childNode->value->kind)
					);

				}

			}

		}

		return $result;

	}

	public function mapType($kind){

		$type = 'text';

		if($kind == "BooleanValue"){
			$type = 'boolean';
		}

		return $type;

	}

	public function mapValue($kind, $value){

		$value = $value;
		if($kind == "BooleanValue"){
			$value = ($value ? 'true' : 'false');
		}

		return $value;

	}

}