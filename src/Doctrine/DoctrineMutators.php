<?php

namespace RateHub\GraphQL\Doctrine;

use RateHub\GraphQL\Interfaces\IGraphQLMutatorProvider;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

use Doctrine\ORM\Query;

/**
 * Class DoctrineMutators
 *
 * Default mutator builder for the Doctrine GraphQL provider.
 *
 * @package App\Api\GraphQL\Doctrine
 */
class DoctrineMutators implements IGraphQLMutatorProvider{

	/**
	 * @var The provider which called this mutator builder
	 */
	private $_typeProvider;

	/**
	 * DoctrineMutators constructor.
	 * @param $typeProvider
	 */
	public function __construct($typeProvider){

		$this->_typeProvider = $typeProvider;

	}

	/**
	 * Generate a list of queries based on the generated types.
	 * @return array
	 */
	public function getMutators(){

		$mutators = [];

		$typeProvider = $this->_typeProvider;

		foreach($typeProvider->getTypeKeys() as $typeKey){

			$permissions = $typeProvider->getPermissions($typeKey);

			$type = $typeProvider->getType($typeKey);

			// Filter out any scalars
			if($type instanceOf ObjectType) {

				// Returns array of identifiers
				$inputType = $typeProvider->getInputType($typeKey);

				if ($inputType !== null) {

					$args = array(
						'items' => array(
							'name' => 'items',
							'type' => Type::listOf($inputType)
						)
					);

					// Build the Create, Update, Delete Mutators
					if($permissions->create)
						$mutators = array_merge($mutators, $this->getCreateMutator($typeKey, $type, $args));

					if($permissions->edit)
						$mutators = array_merge($mutators, $this->getUpdateMutator($typeKey, $type, $args));

					if($permissions->delete)
						$mutators = array_merge($mutators, $this->getDeleteMutator($typeKey, $type, $args));

				}

			}

		}

		return $mutators;

	}

	/**
	 * Generates the create mutator for an object type
	 *
	 * @param $typeKey Type key is the class name,
	 * @param $type Type is the GraphQL output type
	 * @param $args The list of input types.
	 * @return array Returns a list of the output types.
	 */
	public function getCreateMutator($typeKey, $type, $args){

		$mutator = array();

		$name = 'create_' . $type->name;

		$mutator[$name] = [
			'name' => $name,
			'type' => Type::listOf($type),
			'args' => $args,
			'resolve' => function($root, $args, $context, $info) use ($typeKey) {

				$em = $this->_typeProvider->getManager();

				$newEntities = array();

				foreach($args['items'] as $entityProperties){

					// Resolve the graph type to is doctrine entity class
					$entityType = $this->_typeProvider->getTypeClass($typeKey);

					// Create the entity
					$entity = new $entityType();

					// Populate the values
					foreach($entityProperties as $name => $value){

						if(method_exists($entity, 'set')) {
							$entity->set($name, $value);
						}else{
							$entity->$name = $value;
						}

					}

					$em->persist($entity);

					array_push($newEntities, $entity);

				}

				// Commit to database
				$em->flush();

				return $newEntities;

			}

		];

		return $mutator;

	}

	/**
	 * Generates the update mutator for an object type
	 * The update mutator needs to get a reference to the entity
	 * in it's current state, update the values and then commit
	 * them to database.
	 *
	 * @param $typeKey Type key is the class name,
	 * @param $type Type is the GraphQL output type
	 * @param $args The list of input types.
	 * @return array Returns a list of the output types.
	 */
	public function getUpdateMutator($typeKey, $type, $args){

		$mutator = array();

		$provider = $this->_typeProvider;

		$name = 'update_' . $type->name;

		$mutator[$name] = [
			'name' => $name,
			'type' => Type::listOf($type),
			'args' => $args,
			'resolve' => function($root, $args, $context, $info) use ($provider, $typeKey) {

				$em = $this->_typeProvider->getManager();

				// Get the list of id fields
				$identifiers = $provider->getTypeIdentifiers($typeKey);

				// Resolve the graph type to is doctrine entity class
				$entityType = $this->_typeProvider->getTypeClass($typeKey);

				$qb = $em->getRepository($entityType)->createQueryBuilder('e');

				$idList = array();

				$entityPropertiesById = array();

				foreach($args['items'] as $entityProperties){

					$idString = '';

					// Only a single identifier field
					if(count($identifiers) === 1){

						$identifier = $identifiers[0];

						$propertyName = $identifier;
						$propertyValue = $entityProperties[$propertyName];

						$id = array($propertyName => $propertyValue);

						$idString .= $propertyValue;

						array_push($idList, $id);

					// Have a composite identifier
					}else{

						$id = array();

						// Generate a unique identifier by combining the field values
						foreach($identifiers as $identifier) {

							$id[$identifier] = $entityProperties[$identifier];

							$idString .= $entityProperties[$identifier];

						}

						array_push($idList, $id);

					}

					// Store the entity by it's id.
					$entityPropertiesById[$idString] = $entityProperties;

				}

				// BUILD WHERE CLAUSES

				// Single Identifier
				if(count($identifiers) == 1) {

					$fieldName = $identifiers[0];

					$idValues = [];
					foreach($idList as $id){
						$idValues = $id[$fieldName];
					}

					$qb->andWhere($qb->expr()->in('e.' . $fieldName, ':' . $fieldName));
					$qb->setParameter($fieldName, $idValues);

				// Composite Identifier
				}else{

					$cnt = 0;

					$orConditions = [];

					foreach($idList as $id){

						$recordConditions = [];

						foreach($id as $fieldName => $fieldValue){

							array_push($recordConditions, $qb->expr()->eq('e.' . $fieldName, ':' . $fieldName . $cnt));

							$qb->setParameter($fieldName . $cnt, $fieldValue);

						}

						$andX = $qb->expr()->andX();
						$andX->addMultiple($recordConditions);

						array_push($orConditions, $andX);

						$cnt++;

					}

					$orX = $qb->expr()->orX();
					$orX->addMultiple($orConditions);

					$qb->andWhere($orX);

				}

				$query = $qb->getQuery();
				$query->setHint("doctrine.includeMetaColumns", true); // Include associations

				$hydratedResult = array();

				$graphHydrator = new GraphHydrator($em);

				$results = $query->getResult(Query::HYDRATE_ARRAY);

				foreach ($results as $result) {

					$idString = '';

					foreach($identifiers as $identifier) {
						$idString .= $result[$identifier];
					}

					$updates = $entityPropertiesById[$idString];

					// Hydrate before updating, what the changes to trigger unit of work update
					$hydratedObject = $graphHydrator->hydrate($result, $entityType);

					foreach($updates as $name => $values){

						$entity = $hydratedObject->getObject();
						if(method_exists($entity, 'set')){
							$entity->set($name, $values);
						}else{
							$entity->$name = $values;
						}

					}

					$em->getUnitOfWork()->scheduleForUpdate($hydratedObject->getObject());

					array_push($hydratedResult, $hydratedObject);

				}

				$em->flush();

				return $hydratedResult;

			}

		];

		return $mutator;

	}

	/**
	 * Generates the delete mutator for an object type
	 * The delete mutator needs to get a reference to the entity
	 * and then delete it.
	 *
	 * @param $typeKey Type key is the class name,
	 * @param $type Type is the GraphQL output type
	 * @param $args The list of input types.
	 * @return array
	 */
	public function getDeleteMutator($typeKey, $type, $args){

		$provider = $this->_typeProvider;

		$mutator = array();

		$name = 'delete_' . $type->name;

		$mutator[$name] = [
			'name' => $name,
			'type' => Type::boolean(),
			'args' => $args,
			'resolve' => function($root, $args, $context, $info) use ($provider, $typeKey) {

				$em = $this->_typeProvider->getManager();

				// Get the list of id fields
				$identifiers = $provider->getTypeIdentifiers($typeKey);

				$entityType = $this->_typeProvider->getTypeClass($typeKey);

				$qb = $em->getRepository($entityType)->createQueryBuilder('e');

				$idList = array();

				$entityPropertiesById = array();

				foreach($args['items'] as $entityProperties){

					$idString = '';

					// Only a single identifier field
					if(count($identifiers) === 1){

						$identifier = $identifiers[0];

						$propertyName = $identifier;
						$propertyValue = $entityProperties[$propertyName];

						$id = array($propertyName => $propertyValue);

						$idString .= $propertyValue;

						array_push($idList, $id);

					// Have a composite identifier
					}else{

						$id = array();

						// Generate a unique identifier by combining the field values
						foreach($identifiers as $identifier) {

							$id[$identifier] = $entityProperties[$identifier];

							$idString .= $entityProperties[$identifier];

						}

						array_push($idList, $id);

					}

					// Store the entity by it's id.
					$entityPropertiesById[$idString] = $entityProperties;

				}

				// Single Identifier
				if(count($identifiers) == 1) {

					$fieldName = $identifiers[0];

					$idValues = [];
					foreach($idList as $id){
						$idValues = $id[$fieldName];
					}

					$qb->andWhere($qb->expr()->in('e.' . $fieldName, ':' . $fieldName));
					$qb->setParameter($fieldName, $idValues);

				// Composite Identifier
				}else{

					$cnt = 0;

					$orConditions = [];

					foreach($idList as $id){

						$recordConditions = [];

						foreach($id as $fieldName => $fieldValue){

							array_push($recordConditions, $qb->expr()->eq('e.' . $fieldName, ':' . $fieldName . $cnt));

							$qb->setParameter($fieldName . $cnt, $fieldValue);

						}

						$andX = $qb->expr()->andX();
						$andX->addMultiple($recordConditions);

						array_push($orConditions, $andX);

						$cnt++;

					}

					$orX = $qb->expr()->orX();
					$orX->addMultiple($orConditions);

					$qb->andWhere($orX);

				}

				$query = $qb->getQuery();
				$query->setHint("doctrine.includeMetaColumns", true); // Include associations

				$hydratedResult = array();

				$graphHydrator = new GraphHydrator($em);

				foreach ($query->getResult(Query::HYDRATE_ARRAY) as $result) {

					$idString = '';

					foreach ($identifiers as $identifier) {
						$idString .= $result[$identifier];
					}

					$updates = $entityPropertiesById[$idString];

					// Hydrate before updating, want the changes to trigger unit of work update
					$hydratedObject = $graphHydrator->hydrate($result, $entityType);

					$em->remove($hydratedObject->getObject());

				}

				// Commit the changes to database
				try{
					$em->flush();
				}catch(Exception $e){
					return false;
				}

				return true;

			}

		];

		return $mutator;

	}

}