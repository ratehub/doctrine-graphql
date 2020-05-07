<?php

namespace RateHub\GraphQL\Doctrine\Resolvers;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
use RateHub\GraphQL\Interfaces\IGraphQLResolver;

use RateHub\GraphQL\Doctrine\DoctrineDeferredBuffer;
use RateHub\GraphQL\Doctrine\GraphHydrator;
use RateHub\GraphQL\Doctrine\GraphResultList;
use RateHub\GraphQL\Doctrine\GraphPageInfo;

use Doctrine\ORM\Query;


/**
 * Class DoctrineToMany
 *
 * Resolver handles N-to-Many doctrine relationships.
 * Leverages the DoctrineDeferredBuffer and GraphDeferred to delay querying for the new data until
 * all fields have been processed at which point GraphQLPHP process the deferred fields. During
 * the deferred field resolution the buffer is populated 'once' with the required data.
 * Reduces the number of queries from N to 1.
 *
 * @package App\Api\GraphQL\Resolvers
 */
class DoctrineToMany implements IGraphQLResolver {

    /**
     * @var String	Field name as it will be output in GraphQL
     */
    private $name;

    /**
     * @var String	The description for the field. The description is used in
     * 				the Introspective api
     */
    private $description;

    /**
     * @var String	The doctrine type defined as the full class name for the target
     * type.
     */
    private $doctrineClass;

    /**
     * @var String the name of the type as defined in graphql. This is also the key
     * for any mappings done by the DoctrineProvider
     */
    private $graphName;

    /**
     * @var Array	The doctrine association object that defines the relationship
     */
    private $association;

    /**
     * @var	DoctrineProvider	Instance of the doctrine provider that instantiated this resolver.
     */
    private $typeProvider;

    /**
     * @var string 	the key that is used when registering the buffer with the type provider
     */
    private $bufferKey;


    /**
     * DoctrineToMany constructor.
     * @param $provider 		Instance of the doctrine provider
     * @param $name				Field name for the relationship as it should be output in GraphQL
     * @param $description		The description of the field to be used by the introspective api
     * @param $entityType		Class name for the entity
     * @param $association		Doctrine Association object
     */
    public function __construct($provider, $name, $description, $doctrineClass, $graphName, $association){

        $this->name 			= $name;
        $this->description 		= $description;
        $this->doctrineClass	= $doctrineClass;
        $this->graphName		= $graphName;
        $this->association 		= $association;
        $this->typeProvider 	= $provider;

        $this->bufferKey = $graphName . '.' . $association['fieldName'];
    }

    /**
     * Generate the definition for the GraphQL field
     *
     * @return array
     */
    public function getDefinition(){

        // Resolve the types with the provider
        $filterType 	= $this->typeProvider->getFilterType($this->graphName);

        $args = array();

        $outputType = $this->getOutputType();

        foreach(GraphPageInfo::getFilters() as $filter){
            $args[$filter['name']] = array('name' => $filter['name'], 'type' => $filter['type']);
        }

        // Generate the argument definition based on the filter type.
        // No type means the type has no additional inputs
        if($filterType !== null) {
            foreach ($filterType->getFields() as $field) {
                $args[$field->name] = array('name' => $field->name, 'type' => $field->getType());
            }
        }

        // Create and return the definition array
        return array(
            'name' => $this->name,
            'type' => $outputType,
            'args' => $args,
            'resolve' => function($parent, $args, $context, $info){

                $proxyNamespace = $this->typeProvider->getManager()->getConfiguration()->getProxyNamespace() . '\\__CG__\\';

                // Retrieve the id from the parent object.
                // Id can be a single field or a composite.
                $className = str_replace($proxyNamespace, '', get_class($parent->getObject()));

                $parentTypeName = $this->typeProvider->getTypeName($className);

                $sourceIdentifiers = $this->typeProvider->getTypeIdentifiers($parentTypeName);

                $parentDoctrineType = $this->typeProvider->getDoctrineType($parentTypeName);

                $identifier = [];

                foreach($sourceIdentifiers as $field){

                    // We need to determine the source fields that map to the target identifiers;

                    // Is the target identifier an association
                    if($parentDoctrineType->hasAssociation($field)) {

                        $fieldAssociation = $parentDoctrineType->getAssociationMapping($field);

                        $fieldName = $fieldAssociation['joinColumns'][0]['name'];

                        $identifier[$field] = $parent->getDataValue($fieldName);

                        // Just a regular column on the target
                    }else{

                        $identifier[$field] = (is_object($parent) ? $parent->getDataValue($field) : $parent[$field]);

                    }

                }

                // Initialize the buffer, if initialized use the existing one
                $buffer = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $this->bufferKey);

                // Need to defer execution. Add parent ids so that we can query
                // all at once instead of per record
                $buffer->add($identifier);

                // GraphQLPHP will call the deferred resolvers as needed.
                return new \GraphQL\Deferred(function () use ($buffer, $identifier, $args) {

                    // Populate the buffer with the loaded data
                    // For to-Many relationships we can only do bulk queries
                    // if there are no limits on the number of results. Without
                    // data optimizations there is no way to get a list of
                    // multiple parent entities with a to-many relation containing a limit.
                    // Example:
                    // Parent 1
                    //   Association (first 10)
                    // Parent 2
                    //   Association (first 10)
                    // Even doing a single query for Association with limit 20 does
                    // not guarantee the correct result. Suppose Parent 1 has over 20
                    // assocations, Parent 2 would appear to have none.
                    if($this->doBulkLoad($args)) {

                        $this->loadBufferedInBulk($args);

                    }else{

                        $this->loadBuffered($args, $identifier);

                    }

                    $em = $this->typeProvider->getManager();

                    $graphHydrator = new GraphHydrator($em);

                    $dataList = $buffer->result(implode(':', array_values($identifier)));

                    // Process the data results and return a pagination result list.
                    // Result list contains a list of GraphEntities to be traversed
                    // during resolve operations
                    $resultList = new GraphResultList($dataList, $args, $graphHydrator, $this->typeProvider, $this->doctrineClass, $this->graphName);

                    return $resultList;

                });

            }

        );

    }

    /**
     * Determine if we should perform the query for this association in bulk
     * or not.
     *
     * @param $args List of arguments passed to this part of the query
     * @return bool Whether we should perform the query in bulk or not
     */
    public function doBulkLoad($args){

        if(isset($args['first']))
            return false;

        return true;
    }

    /**
     * Load the data for this association as a single query. Will return all assocation rows.
     *
     * @param $args		   The filter arguments for this entity passed via the GraphQL query
     * @param $identifier  Identifier for the parent object as this is an N-to-Many relationship
     */
    public function loadBufferedInBulk($args){

        // Fetch the buffer associated with this type
        // Type is the same as the targetType
        $doctrineTargetType = $this->typeProvider->_doctrineMetadata[$this->graphName];

        $sourceType = $this->typeProvider->getTypeName($this->association['sourceEntity']);
        $targetType = $this->typeProvider->getTypeName($this->association['targetEntity']);

        $doctrineParentType = $this->typeProvider->_doctrineMetadata[$sourceType];

        $sourceIdentifiers = $this->typeProvider->getTypeIdentifiers($sourceType);
        $targetIdentifiers = $this->typeProvider->getTypeIdentifiers($targetType);


        // Fetch the buffer associated with this type
        $buffer = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $this->bufferKey);

        // Have we already loaded to data, if not proceed
        if(!$buffer->isLoaded()) {

            // Fetch the list of parent identifiers
            $parentIds = $buffer->get();

            // Initialize the query build for this type.
            $queryBuilder = $this->typeProvider->getRepository($this->doctrineClass)->createQueryBuilder('e');

            /**
             * In order to resolve the to_many relationship then we need to
             * use the inverse relation to this association
             */
            if ($this->association['type'] === ClassMetadataInfo::ONE_TO_MANY) {

                // We need the association on the target that inverses this assocation. Will be a MANY TO ONE
                // MappedBy represents the property on the target entity that maps to the source entity
                // We need the inverse because that's where the field mapping exists.
                $inverse_association = $doctrineTargetType->getAssociationMapping($this->association['mappedBy']);

                // Single Identifier
                if(count($sourceIdentifiers) == 1) {

                    // Pull out the values from the identifiers
                    $parentIdValues = [];

                    foreach($parentIds as $fieldName => $fieldValue){
                        array_push($parentIdValues, $fieldValue);
                    }

                    $targetField = $inverse_association['fieldName'];

                    $queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $targetField, ':' . $targetField));
                    $queryBuilder->setParameter($targetField, $parentIdValues);

                    // Composite Identifier
                }else{

                    $cnt = 0;

                    $orConditions = [];

                    foreach($parentIds as $parentId){

                        $recordConditions = [];

                        foreach($parentId as $fieldName => $fieldValue){

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

            } else if ($this->association['type'] === ClassMetadataInfo::MANY_TO_MANY) {

                $joinTable					= null;
                $targetField				= null;
                $source_to_target_fields	= null;

                // We need the owning side in order to get the join table properties.
                // We need the inverseAssociation to get the association field
                if($this->association['isOwningSide']) {

                    $inverseAssociation = $doctrineTargetType->getAssociationMapping($this->association['inversedBy']);
                    $joinTable = $this->association['joinTable'];
                    $associationField = $inverseAssociation['fieldName'];

                }else{

                    $inverseAssociation = $doctrineTargetType->getAssociationMapping($this->association['mappedBy']);
                    $joinTable = $inverseAssociation['joinTable'];
                    $associationField = $inverseAssociation['fieldName'];

                }

                if(isset($joinTable)) {

                    $joinCount = 0;


                    $alias = 'e' . $joinCount;
                    $queryBuilder->addSelect($alias)->leftJoin('e.' . $associationField, $alias);

                    // Single Identifier
                    if(count($sourceIdentifiers) == 1) {

                        $fieldName = $sourceIdentifiers[0];

                        $queryBuilder->andWhere($queryBuilder->expr()->in($alias . '.' . $fieldName, ':' . $fieldName));
                        $queryBuilder->setParameter($fieldName, $parentIds);

                        // Composite Identifier
                    }else{

                        $cnt = 0;

                        $orConditions = [];

                        foreach($parentIds as $parentId){

                            $recordConditions = [];

                            foreach($parentId as $fieldName => $fieldValue){

                                array_push($recordConditions, $queryBuilder->expr()->eq($alias . '.' . $fieldName, ':' . $fieldName . $cnt));

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

                    $joinCount++;

                }

            }

            // Add pagination DQL clauses to the query. In this case we'll
            // only be adding the order by statement
            $args = GraphPageInfo::paginateQuery($queryBuilder, $targetIdentifiers, $args);

            $args = GraphPageInfo::sortQuery($queryBuilder, $targetIdentifiers, $args);

            // Add additional where statements based on passed arguments.
            foreach ($args as $name => $values) {

                $queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $name, ':' . $name));
                $queryBuilder->setParameter($name, $values);

            }

            $query = $queryBuilder->getQuery();

            $query->setHint(Query::HINT_INCLUDE_META_COLUMNS, true); // Include associations

            // Use array hydration only. GraphHydrator will handle hydration of doctrine objects.
            $results = $query->getResult(Query::HYDRATE_ARRAY);

            $resultsLoaded = array();

            // Group the results by the parent entity
            foreach ($results as $result) {

                if ($this->association['type'] === ClassMetadataInfo::ONE_TO_MANY) {

                    $inverse_association = $doctrineTargetType->getAssociationMapping($this->association['mappedBy']);

                    // Generate the parent identifier values using the source to target mapped fields
                    $parentIdentifierValues = [];

                    foreach ($sourceIdentifiers as $identifier) {

                        if($doctrineTargetType->hasAssociation($identifier)) {

                            $fieldAssociation = $doctrineTargetType->getAssociationMapping($identifier);

                            $fieldName = $fieldAssociation['joinColumns'][0]['name'];

                            array_push($parentIdentifierValues, $result[$fieldName]);

                            // Just a regular column on the target
                        }else{

                            $source_to_target_fields = $inverse_association['targetToSourceKeyColumns'];

                            $fieldName = $source_to_target_fields[$identifier];

                            array_push($parentIdentifierValues, $result[$fieldName]);

                        }

                    }

                    // Generate the key based on the parent identifier value
                    $parentKey = implode(':', $parentIdentifierValues);

                    // Add the record to the list of related targets for the parent.
                    if (!isset($resultsLoaded[$parentKey]))
                        $resultsLoaded[$parentKey] = array();

                    array_push($resultsLoaded[$parentKey], $result);

                }else if($this->association['type'] === ClassMetadataInfo::MANY_TO_MANY) {

                    if($this->association['isOwningSide']) {

                        $inverseAssociation = $doctrineTargetType->getAssociationMapping($this->association['inversedBy']);
                        $joinTable = $this->association['joinTable'];
                        $associationField = $inverseAssociation['fieldName'];

                    }else{

                        $inverseAssociation = $doctrineTargetType->getAssociationMapping($this->association['mappedBy']);
                        $joinTable = $inverseAssociation['joinTable'];
                        $associationField = $inverseAssociation['fieldName'];

                    }

                    // Query Returns the Target Entity with a list of Source Entities as an array
                    // We use the list of parents to get a list of all the cities for users.
                    $parents = $result[$associationField];


                    // Many to many relationships will generate arrays
                    if(is_array($parents)){

                        foreach($parents as $parent) {

                            $identifiers = [];

                            foreach ($sourceIdentifiers as $identifier) {

                                // Map the identifier to the field value from the query
                                if($doctrineParentType->hasAssociation($identifier)) {

                                    $fieldAssociation = $doctrineParentType->getAssociationMapping($identifier);

                                    $fieldName = $fieldAssociation['joinColumns'][0]['name'];

                                    $identifiers[$identifier] = $parent[$fieldName];

                                    // Just a regular column on the target
                                }else{

                                    $identifiers[$identifier] = (is_object($parent) ? $parent->getDataValue($identifier) : $parent[$identifier]);

                                }

                            }

                            $parentKey = implode(':', $identifiers);

                            if (!isset($resultsLoaded[$parentKey]))
                                $resultsLoaded[$parentKey] = array();

                            array_push($resultsLoaded[$parentKey], $result);

                        }

                        // One to many will generate a single value
                    }else{

                        $parentId = $parents;

                        if (!isset($resultsLoaded[$parentId]))
                            $resultsLoaded[$parentId] = array();

                        array_push($resultsLoaded[$parentId], $result);

                    }

                }

            }

            // Load the buffer with the results.
            $buffer->load($resultsLoaded);

        }

    }

    /**
     * Load the data for this association as multiple queries with limit support.
     * Careful using this method with a heavily nested query. The number of SQL Queries
     * executed can balloon in very quickly.
     *
     * @param $args
     * @param $identifier
     */
    public function loadBuffered($args, $identifier){

        // Fetch the buffer associated with this type
        $type = $this->typeProvider->_doctrineMetadata[$this->graphName];

        // Query name
        $mappedBy	= $this->association['mappedBy'];

        if(!$mappedBy)
            $mappedBy = $this->association['inversedBy'];

        $association = $type->getAssociationMapping($mappedBy);

        // Target entity's association column
        $columnName = $association['joinColumns'][0]['name'];

        // Get the target identifiers, will need them for order and pagination
        $targetIdentifiers = $this->typeProvider->getTypeIdentifiers($association['targetEntity']);

        $graphName = $this->graphName;

        // Fetch the buffer associated with this type
        $buffer 	  = $this->typeProvider->initBuffer(DoctrineDeferredBuffer::class, $this->bufferKey);

        // Have we already loaded to data, if not proceed
        if(!$buffer->isLoaded()) {

            $resultsLoaded = array();

            // Loop through each parent id and perform queries for each to
            // retrieve association data.
            foreach($buffer->get() as $parentId){

                // Create a query using the arguments passed in the query
                $queryBuilder = $this->typeProvider->getRepository($this->doctrineClass)->createQueryBuilder('e');

                if (isset($association['joinColumns'])) {

                    $joinCount = 0;

                    foreach ($association['joinColumns'] as $col) {

                        $targetTable = $association['targetEntity'];

                        $alias = 'e' . $joinCount;
                        $queryBuilder->addSelect($alias)->leftJoin('e.' . $mappedBy, $alias);

                        $identifiers = $buffer->get();

                        foreach($identifiers as $identifier){

                            foreach($targetIdentifiers as $id) {
                                $queryBuilder->andWhere($queryBuilder->expr()->in($alias . '.' . $id, ':' . $id));
                                $queryBuilder->setParameter($id, $identifier[$id]);
                            }

                        }

                        $joinCount++;

                    }

                    $identifiers = $this->typeProvider->getTypeIdentifiers($this->graphName);

                }else {

                    $queryBuilder->andWhere($queryBuilder->expr()->eq('e.' . $mappedBy, ':' . $mappedBy));
                    $queryBuilder->setParameter($mappedBy, $parentId);

                }

                // Add limit and order DQL statements
                $filteredArgs = GraphPageInfo::paginateQuery($queryBuilder, $identifiers, $args);

                $filteredArgs = GraphPageInfo::paginateQuery($queryBuilder, $identifiers, $filteredArgs);

                // Add additional where clauses based on query arguments
                foreach ($filteredArgs as $name => $values) {

                    $queryBuilder->andWhere($queryBuilder->expr()->in('e.' . $name, ':' . $name));
                    $queryBuilder->setParameter($name, $values);

                }

                $query = $queryBuilder->getQuery();

                $query->setHint(Query::HINT_INCLUDE_META_COLUMNS, true); // Include associations

                // Use array hydration only. GraphHydrator will handle hydration of doctrine objects.
                $results = $query->getResult(Query::HYDRATE_ARRAY);

                $parentIdString = implode(':', array_values($parentId));

                $resultsLoaded[$parentIdString] = $results;

            }

            // Load the buffer with the results.
            $buffer->load($resultsLoaded);

        }

    }

    /**
     * Get the output type for the to many relationship. Because it involves a list
     * we'll add pagination support
     *
     * @return null|void
     */
    public function getOutputType(){

        // Retrieve the required types for the result list
        $listType 		= $this->typeProvider->getType($this->graphName);
        $pageInfoType 	= $this->typeProvider->getType(GraphPageInfo::NAME);

        // Define the name
        $outputTypeName = $listType->name . '__List';

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
