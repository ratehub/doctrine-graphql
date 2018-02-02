<?php

namespace RateHub\GraphQL\Doctrine\Authorization;

/**
 * Class Permission
 *
 * Container for some generic permissions
 *
 * @package RateHub\GraphQL\Doctrine
 *
 */
class FieldPermission {

	// Permission Name
	public $name;

	// Boolean: Whether the user has read permission
	private $read;

	// Boolean: Whether the user has edit permission
	private $edit;

	/**
	 * FieldPermission constructor.
	 * @param null $name
	 */
	public function __construct($name = null){

		$this->name 		= $name;
		$this->read 		= false;
		$this->edit			= false;

	}

	/**
	 * Set the Read Permission
	 * @param $value bool
	 */
	public function setRead($value){
		$this->read = $value;
	}

	/**
	 * Get the Read Permission
	 * @return bool
	 */
	public function hasRead(){
		return $this->read;
	}

	/**
	 * Set the Edit Permission
	 * @param $value bool
	 */
	public function setEdit($value){
		$this->edit = $value;
	}

	/**
	 * Get the Edit Permission
	 * @return bool
	 */
	public function hasEdit(){
		return $this->edit;
	}

	/**
	 * Get the read permission name
	 * @return string
	 */
	public function getReadPermissionName(){
		return $this->name . '.read';
	}

	/**
	 * Get the edit permission name
	 * @return string
	 */
	public function getEditPermissionName(){
		return $this->name. '.edit';
	}

	/**
	 * Get all permission names for this field
	 * @return array
	 */
	public function getAllPermissions(){
		return [$this->getReadPermissionName(),
				$this->getEditPermissionName()];
	}

}