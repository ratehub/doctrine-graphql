<?php

namespace RateHub\GraphQL\Interfaces;

/**
 * Interface IGraphQLFilterable
 *
 * If the class provides filter types.
 *
 * @package RateHub\GraphQL\Interfaces
 */
interface IGraphQLFilterable {

	/**
	 * Return the list of filter types.
	 * @return mixed
	 */
	public function getFilters($provider);

}