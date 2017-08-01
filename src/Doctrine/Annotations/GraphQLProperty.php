<?php

namespace RateHub\GraphQL\Doctrine\Annotations;

use Doctrine\Common\Annotations\Annotation;

/**
 * Class GraphQLProperty
 * @package RateHub\Annotation
 *
 * @Annotation
 * @Target({"PROPERTY","METHOD"})
 */
final class GraphQLProperty extends Annotation {

	/**
	 * @var string Name to use when outputting to graphql
	 */
	public $name;

	/**
	 * @var string Data type to use when outputting to graphql
	 */
	public $type;

	/**
	 * @var string Implementation of GraphQLResolver to use
	 */
	public $resolver;

	/**
	 * @var string Property or method description used by the Introspection api
	 */
	public $description;

	/**
	 * @var boolean 	In whitelist mode set to true to include.
	 * 					In blacklist mode set to false to exclude.
	 */
	public $include;

	/**
	 * @var array 		List of namespaces that this property should be included in.
	 */
	public $namespaces;

}