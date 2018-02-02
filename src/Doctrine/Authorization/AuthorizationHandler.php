<?php

namespace RateHub\GraphQL\Doctrine\Authorization;

use RateHub\GraphQL\Interfaces\IGraphQLAuthorizationProvider;

/**
 * Class AuthorizationHandler
 *
 * Default authorization checkes for permissions, always resolves to false.
 *
 * @package RateHub\GraphQL\Doctrine
 */
class AuthorizationHandler implements IGraphQLAuthorizationProvider{

	/**
	 * Given a permission name, return whether permission has been granted.
	 *
	 * @param $permission
	 * @return bool
	 */
	public function hasAccess($permission){

		return false;

	}

}