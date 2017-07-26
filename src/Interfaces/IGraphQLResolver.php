<?php

namespace RateHub\GraphQL\Interfaces;

/**
 * Interface IGraphQLResolver
 *
 * Defines an interface to return the field resolution definition
 * for a GraphQL field
 *
 * @package App\Interfaces
 */
interface IGraphQLResolver {

	/**
	 * Must return an associative array with the following properties:
	 *
	 * name			string		Required. Name of the field. When not set - inferred from fields array key (read about shorthand field definition below)
	 * type			Type		Required. Instance of internal or custom type. Note: type must be represented by single instance within schema (see also Type Registry)
	 * args			array		Array of possible type arguments. Each entry is expected to be an array with keys: name, type, description, defaultValue.
	 * resolve		callback	function($value, $args, $context, GraphQL\Type\Definition\ResolveInfo $info)
	 * 							Given the $value of this type it is expected to return value for current field.
	 * description	string		Plain-text description of this field for clients
	 *
	 */
	function getDefinition();

}