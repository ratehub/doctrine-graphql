<?php

namespace RateHub\GraphQL\Doctrine;

/**
 * Class GraphHydrator
 *
 * Handles the creation of a GraphEntity and the hydration of a model object
 * given an array of it's data retrieved from the database.
 * The GraphEntity doesn't need to be create until all queries are run.
 * Hydration occurs as each record is requested. This prevents
 * unnecessary automatic hydration of any doctrine associations which would generate
 * far to many queries.
 *
 * @package RateHub\GraphQL\Doctrine
 */
class GraphHydrator {

	/**
	 * @var Doctrine Entity Manager
	 */
	public $_em;

	/**
	 * GraphHydrator constructor.
	 * @param $em
	 */
	public function __construct($em){

		$this->_em = $em;

	}

	/**
	 * Create a GraphEntity and generate a doctrine model entity
	 * as well using the supplied data.
	 *
	 * @param $data
	 * @param $entityType
	 * @return GraphEntity|null
	 */
	public function hydrate($data, $entityType)
	{

		// Must have data
		if($data == null)
			return null;

		// Get the doctrine type
		$class = $this->_em->getClassMetadata($entityType);

		$discriminatorColumn = null;

		// Entity is polymorphic, determine the model class based on the
		// Discriminator values
		if(count($class->subClasses) > 0){

			$discriminatorColumn = $class->discriminatorColumn['name'];
			$className = $class->discriminatorMap[$data[$discriminatorColumn]];

			$instanceType = $this->_em->getClassMetadata($className);

		}else{

			$instanceType = $class;

		}


		// Create the doctrine entity and initialize
		$entity = $this->newInstance($instanceType, $this->getId($instanceType, $data));

        if(method_exists($entity, '__init'))
		    $entity->__init();

		// Populate the fields with the data
		foreach ($data as $field => $value) {
			if (isset($instanceType->fieldMappings[$field])) {
				$instanceType->reflFields[$field]->setValue($entity, $value);
			}
		}

		// Register the new entity with doctrine to track field changes
		$this->registerManaged($instanceType, $entity, $data);

		// Return the GraphEntity object which as a proxy
		return new GraphEntity($data, $entity);

	}

	/**
	 * Create a new instance of the doctrine entity and inject the object
	 * manager
	 *
	 * @param $class
	 * @return mixed
	 */
	private function newInstance($class, $id)
	{

        $entity = $this->_em->getProxyFactory()->getProxy($class->name, $id);

		//$entity = $class->newInstance();

		if ($entity instanceof \Doctrine\Common\Persistence\ObjectManagerAware) {
			$entity->injectObjectManager($this->em, $class);
		}

		return $entity;

	}

	protected function getId($class, $data){
        // Generate the unique id
        if ($class->isIdentifierComposite) {
            $id = array();

            foreach ($class->identifier as $fieldName) {
                $id[$fieldName] = isset($class->associationMappings[$fieldName])
                    ? $data[$class->associationMappings[$fieldName]['joinColumns'][0]['name']]
                    : $data[$fieldName];
            }
        } else {
            $fieldName = $class->identifier[0];
            $id        = array(
                $fieldName => isset($class->associationMappings[$fieldName])
                    ? $data[$class->associationMappings[$fieldName]['joinColumns'][0]['name']]
                    : $data[$fieldName]
            );
        }
        return $id;
    }

	/**
	 * Method generates a unique id for the entity based on the values
	 * stored in it's identifier columns. This method is the same as what's
	 * in the doctrine AbstractHydrator id generation. Pulled it in as we
	 * don't need the remaining parts of the abstract hydrator.
     *
	 * @param ClassMetadata $class
	 * @param $entity
	 * @param array $data
	 */
	protected function  registerManaged($class, $entity, array $data)
	{


	    $id = $this->getId($class, $data);

		// Register the entity with doctrine
		$this->_em->getUnitOfWork()->registerManaged($entity, $id, $data);

	}

}
