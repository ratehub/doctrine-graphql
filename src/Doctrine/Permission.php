<?php

namespace RateHub\GraphQL\Doctrine;

/**
 * Class Permission
 *
 * Container for some generic permissions
 *
 * @package RateHub\GraphQL\Doctrine
 */
class Permission {

	public function __construct(){

		$this->create 	= false;
		$this->read 	= false;
		$this->update 	= false;
		$this->delete	= false;

	}

	/**
	 * @var boolean Whether the create permission is set
	 */
	public $create;

	/**
	 * @var boolean Whether the read permission is set
	 */
	public $read;

	/**
	 * @var boolean Whether the update permission is set
	 */
	public $update;

	/**
	 * @var boolean Whether the delete permission is set
	 */
	public $delete;


	/**
	 * Helper method that returns if even a single permission is set.
	 *
	 * @return bool
	 */
	public function hasAccess(){

		if($this->create || $this->read || $this->update || $this->delete)
			return true;

		return false;

	}

}