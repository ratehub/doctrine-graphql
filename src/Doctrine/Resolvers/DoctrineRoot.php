<?php

namespace RateHub\GraphQL\Doctrine\Resolvers;

use RateHub\GraphQL\Interfaces\IGraphQLResolver;

use RateHub\GraphQL\Doctrine\GraphHydrator;
use RateHub\GraphQL\Doctrine\Types\JsonType;
use RateHub\GraphQL\Doctrine\GraphResultList;
use RateHub\GraphQL\Doctrine\GraphPageInfo;

use Doctrine\ORM\Query;

/**
 * Class DoctrineRoot
 *
 * Generates top level queries definitions for each of the doctrine entities.
 *
 * @package App\Api\GraphQL\Doctrine\Resolvers
 */
class DoctrineRoot implements IGraphQLResolver {

	/**
	 * @var String	The GraphQL output type for this this resolver
	 */
	private $type			= null;

	/**
	 * @var String 	The name of the query
	 */
	private $name 			= null;

	/**
	 * @var String	The description for the query. The description is used in
	 * 				the Introspective api
	 */
	private $description 	= null;

	/**
	 * @var String	The entity type defined as the full class name.
	 */
	private $entityType 	= null;

	/**
	 * @var	DoctrineProvider	Instance of the doctrine provider that instantiated this resolver.
	 */
	private $typeProvider 	= null;


	/**
	 * DoctrineRoot constructor.
	 * @param $typeProvider 	The Doctrine provider that instantiated this resolver
	 * @param $typeKey			The Entities class name for the entity
	 * @param $type				The GraphQL output type for this query
	 */
	public function __construct($typeProvider, $typeKey, $type){

		$this->type			= $type;
		$this->name 		= $type->name;
		$this->description 	= $type->description;
		$this->entityType	= $typeKey;
		$this->typeProvider = $typeProvider;

	}

	/**
	 * Generate the definition for the GraphQL field
	 *
	 * @return array
	 */
	public function getDefinition(){

		// Resolve the type with the provider
		$inputType = $this->typeProvider->getQueryFilterType($this->entityType);

		$outputType = $this->getOutputType();

		$args = array();

		// This can happen if there's only a one-many association
		// If this is the case then the type has no args
		if($inputType !== null) {

			foreach ($inputType->getFields() as $field) {
				$args[$field->name] = array('name' => $field->name, 'type' => $field->getType());
			}

		}

		$resolver = $this;

		// Create and return the definition array
		return array(
			'name' 		=> $this->name,
			'type' 		=> $outputType,
			'args' 		=> $args,
			'resolve' 	=> function($root, $args, $context, $info){

				$em = $this->typeProvider->getManager();

				$config = $em->getConfiguration();
				$config->addCustomStringFunction('JSON_PATH_EQUALS', 'App\Api\GraphQL\Doctrine\JsonPathEquals');

				// Create a query using the arguments passed in the query
				$qb = $em->getRepository($this->entityType)->createQueryBuilder('e');

				$inputType = $this->typeProvider->getQueryFilterType($this->entityType);

				// Retrieve the identifiers to be used for pagination
				$identifiers = $this->typeProvider->getTypeIdentifiers($this->entityType);

				// Add the appropriate DQL clauses required for pagination based
				// on the supplied args. Args get removed once used.
				$filteredArgs = GraphPageInfo::paginateQuery($qb, $identifiers, $args);

				// Add additional WHERE clauses based are filters
				foreach ($filteredArgs as $name => $values) {

					$fields 	= $inputType->getFields();
					$fieldType 	= $fields[$name]->getType();

					// If on of the argument fields is json then we need to do the comparison using
					// the JSON_PATH_EQUALS method
					if ($fieldType instanceOf JsonType) {

						foreach ($values as $filter) {

							foreach ($filter as $path => $valueInfo) {

								$value = $valueInfo['value'];
								$valueType = $valueInfo['type'];

								if ($valueType === 'text')
									$value = '\'' . $value . '\'';

								//$qb->andWhere("CAST(e." . $name . ", 'mortgage', 'boolean') = true");
								$qb->andWhere("JSON_PATH_EQUALS(e." . $name . ", '" . $path . "', '" . $valueType . "') = " . $value);

							}

						}

					// Otherwise add a generic filter to the query
					} else {

						$qb->andWhere($qb->expr()->in('e.' . $name, ':' . $name));
						$qb->setParameter($name, $values);

					}

				}

				$query = $qb->getQuery();
				$query->setHint("doctrine.includeMetaColumns", true); // Include associations

				$graphHydrator = new GraphHydrator($em);

				$dataList = $query->getResult(Query::HYDRATE_ARRAY);

				// Process the data results and return a pagination result list.
				// Result list contains a list of GraphEntities to be traversed
				// during resolve operations
				$resultList = new GraphResultList($dataList, $args, $graphHydrator, $this->typeProvider, $this->entityType);

				return $resultList;

			}


		);

	}

	/**
	 * Get the output type for the to-many relationship. Because it involves a list
	 * we'll add pagination support
	 *
	 * @return null|void
	 */
	public function getOutputType(){

		// Retrieve the required types for the result list
		$listType 		= $this->type;
		$pageInfoType 	= $this->typeProvider->getType(GraphPageInfo::NAME);

		// Define the name
		$outputTypeName = $this->name . '__List';

		// Multiple associations can be related to the same object resulting
		// in the same output type. Only need one instance instantiated.
		if($this->typeProvider->getType($outputTypeName) === null){

			$outputType = GraphResultList::getType($outputTypeName, $listType, $pageInfoType);

			$this->typeProvider->addType($outputTypeName, $outputType);

		// Already been defined
		}else{

			$outputType =  $this->typeProvider->getType($outputTypeName);

		}

		return $outputType;

	}

}