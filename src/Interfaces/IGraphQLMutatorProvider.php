<?php

namespace RateHub\GraphQL\Interfaces;

/**
 * Interface IGraphQLMutatorProvider
 *
 * Defines an interface to return a list of top level queries
 * for the GraphQL Schema
 *
 * @package App\Interfaces
 */
interface IGraphQLMutatorProvider {

    /**
     * Return the list of mutators defined by the provider.
     *
     * @return mixed
     */
	public function getMutators();

}