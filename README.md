# doctrine-graphql

Generates all the necessary types, queries and mutators using the Doctrine
model metadata. All standard data fetching uses the doctrine Array Hydrator. This provider
will handle the Object hydration once all queries are finished and the data is ready to be returned.
 
## Features
 
### Whitelist/Blacklist 

 The provider can be setup to filter what objects and properties are included in the final graphql api.
 *  Whitelisting will only include types/properties that are marked for inclusion
 *  Blacklisting will only include types/properties that are not marked for exclusion

### Namespaces			

 Along with white and blacklists types and properties can be associated to different namespaces
 and specify CRED operations that are allowed for those namespaces. 
 
### Naming Overrides 	

 Names and descriptions of types and methods can be overwritten via the GraphQLType and GraphQLProperty annotations. Field Names are not yet able to be overwritten.
 
### Deferred Loading	

 Associations take advantage of deferred loading via the DeferredBuffer. This is used to significantly   reduce the number of queries and solves the N+1 problem when
 loading data (https://secure.phabricator.com/book/phabcontrib/article/n_plus_one/)
 
### Custom Resolvers	

 Any property can have it's own custom resolver instead of the standard one provided
 by the provider.
 
### Pagination

 The provider supports key and offset pagination for top level queries. Also supports limiting
 results returned in an n-to-Many relationship.
 
### Polymorphic Entities	

 Provider supports querying polymorphic entities and querying unique fields per type
 using the GraphQL inline fragments.
 
## Getting Started
 
 After initializing doctrine you'll need to register
 the graphql annotations:
 
 ```php
 use RateHub\GraphQL\Doctrine\AnnotationLoader;
 use Doctrine\Common\Annotations\AnnotationRegistry;
  
 AnnotationRegistry::registerLoader(array(new AnnotationLoader(), "load"));
 ```
 
 The next step is to initialize the graphql schema:
 
 ```php
 use RateHub\GraphQL\Doctrine\DoctrineProvider;
 use RateHub\GraphQL\Doctrine\DoctrineProviderOptions;
 use RateHub\GraphQL\GraphContext;
 use GraphQL\Type\Definition\ObjectType;
 use GraphQL\Schema;
 
 // Set the options including any extensions
 $options = new DoctrineProviderOptions();
 $options->em = $em; // EntityManager initialized within your as app as needed
 $options->filter = 'blacklist'
 
 // Initialize the Provider. With blacklist filtering, no
 // annotations are needed unless something needs to be 
 // excluded. The provider will generate all queries, 
 // mutators and types needed.
 $provider = new DoctrineProvider('default', $options);
 
 // Initialize top level types
 $context = new GraphContext();
 $queryType = new ObjectType('query', $provider->getQueries());
 $mutatorType = new ObjectType('mutator', $provider->getMutators());
 
 // Initialize the schema
 $schema = new Schema([
                       'query' => $queryType,
                       'mutation' => $mutatorType,
                       'types' => $provider->getTypes()
                     ]);
 
 ```
 
 From here you can execute a query:
 
 ```php
    $result = \GraphQL\GraphQL::execute(
        $schema,
        $params['query'], // Request parameter containing the graphql query
        null,
        $context,
        null
    );
 ```
