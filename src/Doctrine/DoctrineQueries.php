<?php

namespace RateHub\GraphQL\Doctrine;

use RateHub\GraphQL\Interfaces\IGraphQLQueryProvider;
use RateHub\GraphQL\Doctrine\Resolvers\DoctrineRoot;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\InterfaceType;

/**
 * Class DoctrineQueries
 *
 * Default query builder for the Doctrine GraphQL provider.
 *
 * @package App\Api\GraphQL\Doctrine
 */
class DoctrineQueries implements IGraphQLQueryProvider{

	/**
	 * @var The provider which called this query builder
	 */
	private $_typeProvider;

	/**
	 * DoctrineQueries constructor.
	 * @param $typeProvider
	 */
	public function __construct($typeProvider){

		$this->_typeProvider = $typeProvider;

	}

	/**
	 * Generate a list of queries based on the generated types.
	 * @return array
	 */
	public function getQueries(){

		$queries = [];

		foreach($this->_typeProvider->getTypeKeys() as $typeKey){

			$permissions = $this->_typeProvider->getPermissions($typeKey);

			$type = $this->_typeProvider->getType($typeKey);

			if($permissions->read) {

				// Filter out any scalars
				if ($type instanceOf ObjectType || $type instanceOf InterfaceType) {

					// Use the Root level resolver to generate the query
					$resolver = new DoctrineRoot($this->_typeProvider, $typeKey, $type);

					$queries[$type->name] = $resolver->getDefinition();

				}

			}

		}

		return $queries;

	}

}