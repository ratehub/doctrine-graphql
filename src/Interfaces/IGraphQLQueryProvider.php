<?php

namespace RateHub\GraphQL\Interfaces;

/**
 * Interface IGraphQLQueryProvider
 *
 * Defines an interface to return a list of top level queries
 * for the GraphQL Schema
 *
 * @package App\Interfaces
 */
interface IGraphQLQueryProvider {

    /**
     * Return the list of top level queries defined by the provider
     *
     * @return mixed
     */
	public function getQueries();

}