<?php

namespace RateHub\GraphQL\Interfaces;


/**
 * Interface IGraphQLTypeFactory
 *
 * Defines an interface to obtain an object type to be used
 * by GraphQL
 *
 * @package App\Interfaces
 */
interface IGraphQLProvider {

	function __construct($name, $options);

	/**
	 * Return the type defined by the type name
	 *
	 * @param $typeName
	 * @return mixed
	 */
	function getType($typeName);

	/**
	 * Get All types
	 *
	 * @return mixed
	 */
	function getTypes();

    /**
     * Return the list of top level queries
     *
     * @return mixed
     */
	function getQueries();

    /**
     * Return the list of mutators
     *
     * @return mixed
     */
	function getMutators();

}