# doctrine-graphql

Generates all the necessary types, queries and mutators using the Doctrine
model metadata. All standard data fetching uses the doctrine Array Hydrator. This provider
will handle the Object hydration once all queries are finished and the data is ready to be returned.
 
## Features
 
### Whitelist/Blacklist 

 The provider can be setup to filter what objects and properties are included in the final graphql api.
 *  Whitelisting will only include types/properties that are marked for inclusion
 *  Blacklisting will only include types/properties that are not marked for exclusion
 
### Naming Overrides 	

Names and descriptions of types and methods can be overwritten via the GraphQLType and GraphQLProperty annotations. Field Names are not yet able to be overwritten.
 
### Deferred Loading	

Associations take advantage of deferred loading the a DeferredBuffer. This is used to significantly   reduce the number of queries and solves the N+1 problem when
loading data (https://secure.phabricator.com/book/phabcontrib/article/n_plus_one/)
 
### Custom Resolvers	

Any property can have it's own custom resolver instead of the standard one provided
by the provider.
 
### Pagination

The provider supports key and offset pagination for top level queries. Also supports limiting
results returned in an n-to-Many relationship.
 
### Polymorphic Entities	

Provider provides support for querying polymorphic entities and quering unique fields per type
using the GraphQL inline fragments.
 
 
