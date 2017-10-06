<?php

namespace RateHub\GraphQL\Doctrine;

/**
 * Class DoctrineDeferredBuffer
 *
 * Construct to store a list of ids and their related records.
 * Multiple resolvers can use the same buffer so only one query
 * needs to be run.
 *
 * @package App\Api\GraphQL
 */
class DoctrineDeferredBuffer {

	/**
	 * @var array List of Ids;
	 */
	public $buffer 	= array();

	/**
	 * @var array List of Entities
	 */
	public $results = array();

	/**
	 * @var bool Whether the entities have been queried yet
	 */
	public $loaded 	= false;

	/**
	 * Add an id to the buffer
	 *
	 * @param $id
	 */
	public function add($id){

		// Don't add duplicates unnecessarily
		if(!in_array($id, $this->buffer))
			array_push($this->buffer, $id);

	}

	/**
	 * Whether the records have been loaded in to the buffer.
	 *
	 * @return bool
	 */
	public function isLoaded(){

		return $this->loaded;

	}

	/**
	 * Load the entities in to the buffers results list
	 *
	 * @param $items
	 */
	public function load($items){

		$this->loaded = true;

		$this->results = $items;

	}

	/**
	 * Get the result for a particular id.
	 *
	 * @param $id
	 * @return mixed
	 */
	public function result($id){

	    if(isset($this->results[$id]))
		    return $this->results[$id];
	    return null;

	}

	/**
	 * Get the list of ids for querying.
	 *
	 * @return array
	 */
	public function get(){
		return $this->buffer;
	}

}