<?php

use \RateHub\GraphQL\Interfaces\IGraphQLAuthorizationProvider;

/**
 * Class AuthorizationHandlerTest
 *
 * Test implementation with hooks that allow for testing of the
 * authorization layer.
 *
 */
class AuthorizationHandlerTest implements IGraphQLAuthorizationProvider {


	/**
	 * Defaults to true for all permissions.
	 *
	 * @param $permission
	 * @return bool
	 */
	public function hasAccess($permission){

		return true;

	}

}