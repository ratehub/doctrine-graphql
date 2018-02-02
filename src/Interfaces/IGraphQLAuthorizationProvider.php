<?php

namespace RateHub\GraphQL\Interfaces;

/**
 * Interface IGraphQLAuthorizationProvider
 *
 * The Doctrine provider accepts an implementation of this interface.
 * For each object/type and field the doctrine provider will verify permissions with
 * the authorization provider. If false then the type or field is not included
 * in the schema.
 *
 * @package RateHub\GraphQL\Interfaces
 */
interface IGraphQLAuthorizationProvider {

	/**
	 * Return whether the user has access via the permission
	 *
	 * @param $permission
	 * @return bool
	 */
	public function hasAccess($permission);
	
}