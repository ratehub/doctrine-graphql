<?php

namespace RateHub\GraphQL\Doctrine\Annotations;

use Doctrine\Common\Annotations\Annotation;

/**
 * Class GraphQLType
 * @package RateHub\Annotation
 *
 * @Annotation
 * @Target("CLASS")
 */
final class GraphQLType extends Annotation {

	/**
	 * @var string name to use when outputting to graphql
	 */
	public $name;

	/**
	 * @var string Property or method description used by the Introspection api
	 */
	public $description;

	/**
	 * @var boolean		In whitelist mode set to true to include.
	 * 					In blacklist mode set to false to exclude.
	 */
	public $include;


}