<?php

namespace RateHub\GraphQL\Doctrine;

/**
 * Class GraphEntity
 *
 * GraphEntity acts as a proxy to doctrine entities when
 * traversing a graphql result tree. Allows for retrieving
 * of an association value without triggering the doctrine
 * hydration of the related object.
 *
 * @package RateHub\GraphQL\Doctrine
 */
class GraphEntity {

	/**
	 * @var Array of result data
	 */
	private $_data;

	/**
	 * @var Doctrine entity object
	 */
	private $_object;

	/**
	 * GraphEntity constructor.
	 * @param $data	   Array of data from query result
	 * @param $object  Doctrine entity
	 */
	public function __construct($data, $object){

		$this->_data 	= $data;
		$this->_object 	= $object;

	}

	/**
	 * Return the value for a specific data column
	 * @param $key
	 * @return mixed
	 */
	public function getDataValue($key){

		return $this->_data[$key];

	}

	/**
	 * Return the doctrine entity
	 * @return Doctrine
	 */
	public function getObject(){

		return $this->_object;

	}

	/**
	 * Call the getter one the entity
	 * @param $key
	 * @return mixed
	 */
	public function get($key){
		$methodName = 'get' . $key;

		if(method_exists($this->_object, $methodName)) {
			$value = $this->_object->$methodName();
		}else {

			if(method_exists($this->_object, 'get')) {
				$value = $this->_object->get($key);
			}else {
				$value = $this->_object->$key;
			}

		}

		return $value;
	}

	/**
	 * Call a method on the entity
	 * @param $methodName
	 * @return mixed
	 */
	public function getByMethod($methodName){
		$value = $this->_object->$methodName();
		return $value;
	}

}