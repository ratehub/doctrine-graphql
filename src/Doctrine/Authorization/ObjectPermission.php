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
class ObjectPermission {

	// Permission Name
	public $name;

	// Boolean: Whether the user has create permission for the type
	private $create;

	// Boolean: Whether the user has read permission for the type
	private $read;

	// Boolean: Whether the user has edit permission for the type
	private $edit;

	// Boolean: Whether the user has delete permission for the type
	private $delete;

	/**
	 * List of all the field permissions associated with this object.
	 *
	 * @var array<FieldPermission>
	 */
	public $fieldPermissions;

	/**
	 * ObjectPermission constructor.
	 * @param null $name Name of the object/type
	 */
	public function __construct($name = null){

		$this->name 	= $name;
		$this->read 	= false;
		$this->create 	= false;
		$this->edit		= false;
		$this->delete	= false;

		$this->fieldPermissions = [];

	}

	/**
	 * Set the Create Permission
	 * @param $value
	 */
	public function setCreate($value){
		$this->create = $value;
	}

	/**
	 * Get the Create Permission
	 * @return bool
	 */
	public function hasCreate(){
		return $this->create;
	}

	/**
	 * Set the Read Permission
	 * @param $value
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
	 * @param $value
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
	 * Set the Delete Permission
	 * @param $value
	 */
	public function setDelete($value){
		$this->delete = $value;
	}

	/**
	 * Get the Edit Permission
	 * @return bool
	 */
	public function hasDelete(){
		return $this->delete;
	}

	/**
	 * Add a field permission to the objects list
	 *
	 * @param FieldPermission $permission
	 */
	public function addFieldPermission(FieldPermission $permission){

		$this->fieldPermissions[$permission->name] = $permission;

	}

	/**
	 * Get a specific field permission associated to the object
	 *
	 * @param $name
	 * @return mixed
	 */
	public function getFieldPermission($name){

		return $this->fieldPermissions[$permission->name] = $permission;

	}

	/**
	 * Get the create permission name
	 * @return string
	 */
	public function getCreatePermissionName(){
		return $this->name . '.create';
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
		return $this->name . '.edit';
	}

	/**
	 * Get the delete permission name
	 * @return string
	 */
	public function getDeletePermissionName(){
		return $this->name . '.delete';
	}

	/**
	 * Get a list of all the permission names associated
	 * with the object.
	 *
	 * @return array
	 */
	public function getAllPermissions(){

		$permissions = [$this->getCreatePermissionName(),
						$this->getReadPermissionName(),
						$this->getEditPermissionName(),
						$this->getDeletePermissionName()];

		foreach($this->fieldPermissions as $fieldPermission){

			foreach($fieldPermission->getAllPermissions() as $actionPermission){
				array_push($permissions, $actionPermission);
			}

		}

		return $permissions;

	}

}