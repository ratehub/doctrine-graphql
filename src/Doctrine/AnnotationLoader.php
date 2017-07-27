<?php

namespace RateHub\GraphQL\Doctrine;

/**
 * Class AnnotationLoader
 *
 * Handles loading the annotations silently.
 *
 * @package RateHub\GraphQL\Doctrine
 */
class AnnotationLoader {

	/**
	 * @param $class  Class container the annotation. We're only interested in our own
	 * @return bool	  Successfully loaded or not
	 */
	public function load($class){

		if(strpos($class, 'RateHub\GraphQL\Doctrine\Annotations') === 0) {

			$file = $this->getBase() . str_replace('RateHub\GraphQL\Doctrine\Annotations\\', '', $class) . ".php";

			// File exists makes sure that the loader fails silently
			if (file_exists($file)) {

				require $file;

				return true;
			}

		}

		return false;

	}

	/**
	 * Utility method to be the base path of the annotation classes
	 *
	 * @return string
	 */
	public function getBase(){

		return __DIR__ . '/Annotations/';

	}

}