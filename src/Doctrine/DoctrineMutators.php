<?php

namespace RateHub\GraphQL\Doctrine;

use RateHub\Extension\Doctrine\Hstore;
use RateHub\GraphQL\Doctrine\Types\HstoreType;
use RateHub\GraphQL\Interfaces\IGraphQLMutatorProvider;
use RateHub\GraphQL\Doctrine\Resolvers\DoctrineToMany;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

use Doctrine\ORM\Query;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\Common\Collections\ArrayCollection;

use Doctrine\DBAL\Types\Type as DType;

class GraphProxy{

}

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
					if($permissions->hasCreate())
						$mutators = array_merge($mutators, $this->getCreateMutator($typeKey, $type, $args));

					if($permissions->hasEdit())
						$mutators = array_merge($mutators, $this->getUpdateMutator($typeKey, $type, $args));

					if($permissions->hasDelete())
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

					$doctrineType = $this->_typeProvider->getDoctrineType($typeKey);

					// Resolve the graph type to is doctrine entity class
					$doctrineClass = $this->_typeProvider->getTypeClass($typeKey);

					// Create the entity
					$entity = new $doctrineClass();

					$graphEntityData = $em->getUnitOfWork()->getOriginalEntityData($entity);

					// This will have to be recursive

					// Populate the values
					foreach($entityProperties as $name => $value){

						if($doctrineType->hasAssociation($name)){

							$association = $doctrineType->getAssociationMapping($name);

							if ($association['type'] === ClassMetadataInfo::ONE_TO_ONE || $association['type'] === ClassMetadataInfo::MANY_TO_ONE){

								$associationTypeKey = $this->_typeProvider->getTypeName($association['targetEntity']);
								$associationClass = $this->_typeProvider->getTypeClass($associationTypeKey);
								$associationType  = $this->_typeProvider->getDoctrineType($associationTypeKey);

								$associationProperties = $value;

								$identifiers = $associationType->getIdentifier();

								$findId = array();
								foreach ($identifiers as $id) {
									$findId[$id] = $value[$id];
								}

								$relatedEntity = $em->find($associationClass, $findId);

								if($relatedEntity === null) {

									$relatedEntity = new $associationClass();

									foreach ($associationProperties as $aname => $avalue) {

										$relatedEntity->$aname = $avalue;

									}

									$em->persist($relatedEntity);

								}

								// We need to generate the graphEntityData as if it were retrieved with array hydration
								// Only concerned with the identifiers as they'll be used to retrieve the related records
								// as part of building the final result
								foreach($identifiers as $id){

									if (isset($association['joinColumns'])) {

										foreach ($association['joinColumns'] as $col) {
											$graphEntityData[$col['name']] = $relatedEntity->{$col['referencedColumnName']};
										}

									}else{

										$graphEntityData[$id] = $relatedEntity->$id;

									}

								}

								$entity->$name = $relatedEntity;

							}else{

									// Handle Many to Many

								$associationTypeKey = $this->_typeProvider->getTypeName($association['targetEntity']);
								$associationClass = $this->_typeProvider->getTypeClass($associationTypeKey);
								$associationType = $this->_typeProvider->getDoctrineType($associationTypeKey);

								$collection = new ArrayCollection(); // $entity->$name;

								$array_collection = []; // Used to store data as if it had be retrieved via array hydration

								$values = $value;

								if ($values) {

									$associatedEntities = $this->getEntitiesById($associationType, $values);

									foreach ($associatedEntities as $associatedEntity) {

										if (!$collection->contains($associatedEntity)) {
											// add new item
											$collection->add($associatedEntity);

											// update inverse collection
											$inverseCollection = $associatedEntity->{$association['inversedBy']};
											if (!$inverseCollection->contains($entity)) {
												$inverseCollection->add($entity);
											}

										}

									}
								}

								$entity->$name = $collection;

							}


						}else {

							if (method_exists($entity, 'set')) {
								$entity->set($name, $value);
							} else {
								$entity->$name = $value;
							}

							$graphEntityData[$name] = $value;

						}

					}

					$em->persist($entity);

					$identifiers = $doctrineType->getIdentifier();
					foreach($identifiers as $id) {
						$graphEntityData[$id] = $entity->$id;
					}

					array_push($newEntities, new GraphEntity($graphEntityData, $entity));

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
				$entityName = $this->_typeProvider->getTypeClass($typeKey);

				$entityType = $this->_typeProvider->getDoctrineType($typeKey);

				$qb = $em->getRepository($entityName)->createQueryBuilder('e');

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

				$updatedResult = [];

				$results = $this->getEntitiesById($entityType, $idList);

				foreach ($results as $result) {

					$idString = '';

					foreach ($identifiers as $identifier) {
						$idString .= $result->$identifier;
					}

					$updates = $entityPropertiesById[$idString];

					// Need to handle field types and associations
					$doctrineType = $this->_typeProvider->getDoctrineType($typeKey);

					$entity = $result;

					$graphEntityData = $em->getUnitOfWork()->getOriginalEntityData($entity);

					// Populate the values
					foreach ($updates as $name => $value) {

						if ($doctrineType->hasAssociation($name)) {

							$association = $doctrineType->getAssociationMapping($name);

							// HANDLE n-to-ONE
							if ($association['type'] === ClassMetadataInfo::ONE_TO_ONE || $association['type'] === ClassMetadataInfo::MANY_TO_ONE) {

								$associationTypeKey = $this->_typeProvider->getTypeName($association['targetEntity']);
								$associationClass = $this->_typeProvider->getTypeClass($associationTypeKey);
								$associationType = $this->_typeProvider->getDoctrineType($associationTypeKey);

								$associationProperties = $value;

								$identifiers = $associationType->getIdentifier();

								$findId = array();
								foreach ($identifiers as $id) {
									if(isset($value[$id]))
										$findId[$id] = $value[$id];
								}

								$relatedEntity = null;

								if(count($findId) === count($identifiers)) {

									$relatedEntity = $em->find($associationClass, $findId);

									// Try to find the related entity, if one cannot be found then
									// create it.
									if ($relatedEntity === null) {

										$relatedEntity = new $associationClass();

										foreach ($associationProperties as $aname => $avalue) {

											$relatedEntity->$aname = $avalue;

										}

										$em->persist($relatedEntity);

									}

									// We need to generate the graphEntityData as if it were retrieved with array hydration
									// Only concerned with the identifiers as they'll be used to retrieve the related records
									// as part of building the final result
									foreach ($identifiers as $id) {

										// joinColumns is set for a many to one association
										if (isset($association['joinColumns'])) {

											foreach ($association['joinColumns'] as $col) {
												$graphEntityData[$col['name']] = $relatedEntity->{$col['referencedColumnName']};
												$entity->{$col['name']} = $relatedEntity->{$col['referencedColumnName']};
											}

										} else {

											$graphEntityData[$id] = $relatedEntity->$id;

										}

									}

								}

								$entity->$name = $relatedEntity;

							// HANDLE n-to-MANY
							} else {

								$associationTypeKey = $this->_typeProvider->getTypeName($association['targetEntity']);
								$associationClass = $this->_typeProvider->getTypeClass($associationTypeKey);
								$associationType = $this->_typeProvider->getDoctrineType($associationTypeKey);

								$collection = $entity->$name;

								// make a copy to keep track of which items we've seen
								$originalCollection = clone($collection);

								$values = $value;

								if ($values) {

									$associatedEntities = $this->getEntitiesById($associationType, $values);

									foreach ($associatedEntities as $associatedEntity) {

										if (!$collection->contains($associatedEntity)) {
											// add new item
											$collection->add($associatedEntity);

											// update inverse collection
											$inverseCollection = $associatedEntity->{$association['inversedBy']};
											if (!$inverseCollection->contains($entity)) {
												$inverseCollection->add($entity);
											}
										}

										// remove any items we see from the copied collection, anything left needs to be removed
										$originalCollection->removeElement($associatedEntity);
									}
								}

								// remove items still left in the copied collection
								foreach ($originalCollection as $removed) {
									$collection->removeElement($removed);

									// update inverse collection
									$inverseCollection = $removed->{$association['inversedBy']};
									if ($inverseCollection->contains($entity)) {
										$inverseCollection->removeElement($entity);
									}
								}


							}

						} else {

							$ftype = $doctrineType->getTypeOfField($name);

							$graphEntityData[$name] = $value;

							if($ftype === 'hstore' || $ftype === 'json'){
								$value = (object)$value;
							}

							if (method_exists($entity, 'set')) {
								$entity->set($name, $value);
							} else {
								$entity->$name = $value;
							}



						}

					}

					$em->getUnitOfWork()->scheduleForUpdate($entity);
					array_push($updatedResult, new GraphEntity($graphEntityData, $entity));

				}

				$em->flush();

				return $updatedResult;

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

	/**
	 * Get a list of entities by their id
	 *
	 * @param $typeProvider
	 * @param $entityType
	 * @param array $ids
	 * @return array
	 */
	public function getEntitiesById($entityType, $ids = []){

		if(count($ids) === 0){
			return [];
		}

		$em = $this->_typeProvider->getManager();

		$identifiers = $entityType->getIdentifier();

		$queryBuilder = $this->_typeProvider->getRepository($entityType->getName())->createQueryBuilder('e');

		$cnt = 0;

		$orConditions = [];

		$idList = [];

		// Single Identifier
		if (count($identifiers) === 1) {

			//$idList = [];

			$idField = $identifiers[0];

			foreach($ids as $id){
				array_push($idList, $id[$idField]);
			}

			$queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $idField, ':' . $idField . $cnt));
			$queryBuilder->setParameter($idField . $cnt, $idList);

		// Composite Identifier
		}else {

			foreach($ids as $id) {

				$recordConditions = [];

				foreach ($id as $fieldName => $fieldValue) {

					array_push($recordConditions, $queryBuilder->expr()->eq('e.' . $fieldName, ':' . $fieldName . $cnt));

					$queryBuilder->setParameter($fieldName . $cnt, $fieldValue);

				}

				$andX = $queryBuilder->expr()->andX();
				$andX->addMultiple($recordConditions);

				array_push($orConditions, $andX);

				$cnt++;

			}

			$orX = $queryBuilder->expr()->orX();
			$orX->addMultiple($orConditions);

			$queryBuilder->andWhere($orX);

		}

		$joins = [];

		foreach ($entityType->getAssociationMappings() as $name=>$association) {

			if ($association['isOwningSide'] || $association['type'] & ClassMetadataInfo::TO_ONE ||
					$association['fetch'] == ClassMetadataInfo::FETCH_EAGER) {

				$join = 'e.'.$name;
				$alias = 'e'.count($joins);
				$queryBuilder->addSelect($alias)->leftJoin('e.'.$name, $alias);
				$joins[$join] = $alias;

				// eager load where specified
				$targetClass = $em->getClassMetadata($association['targetEntity']);
				$targetAssociations = $targetClass->getAssociationMappings();

				foreach ($targetAssociations as $name=>$association) {
					if ($association['fetch'] == ClassMetadataInfo::FETCH_EAGER) {
						$join = $alias.'.'.$name;
						$alias = 'e'.count($joins);
						$queryBuilder->addSelect($alias)->leftJoin($join, $alias);
						$joins[$join] = $alias;
					}
				}
			}


		}

		$query = $queryBuilder->getQuery();
		$query->setHint("doctrine.includeMetaColumns", true);

		// Use array hydration only. GraphHydrator will handle hydration of doctrine objects.
		$results = $query->getResult();

		return $results;

	}

}