<?php

use Doctrine\DBAL\Schema\Table;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Schema;

use RateHub\GraphQL\GraphContext;
use RateHub\GraphQL\Doctrine\DoctrineProvider;
use RateHub\GraphQL\Doctrine\DoctrineProviderOptions;

use DoctrineGraph\Schema\GQL001\GQL001_Project;
use DoctrineGraph\Schema\GQL001\GQL001_User;
use DoctrineGraph\Schema\GQL001\GQL001_City;
use DoctrineGraph\Schema\GQL001\GQL001_Interest;
use DoctrineGraph\Schema\GQL001\GQL001_Province;
use DoctrineGraph\Schema\GQL001\GQL001_Location;
use DoctrineGraph\Schema\GQL001\GQL001_Sector;

use Doctrine\DBAL\Types\Type;

class TestFactoryDefault implements TestFactory{

	const SCHEMA = 'default';

	public $em;

	public function __construct(){

		$this->em = TestDb::createEntityManager(self::SCHEMA);

	}

	public function reset(){

		Type::overrideType('datetime', UTCDateTimeType::class);
		Type::overrideType('datetimetz', UTCDateTimeType::class);

		if(!Type::hasType('hstore'))
			Type::addType('hstore', Hstore::class);

		$this->dropSchema();

		$this->em->clear();

		$this->buildSchema();

	}

	public function getEntityManager() {

		return $this->em;

	}

	public function getProvider() {

		$option = new DoctrineProviderOptions();
		$option->scalars = [
			'datetime'  => \RateHub\GraphQL\Doctrine\Types\DateTimeType::class,
			'array'     => \RateHub\GraphQL\Doctrine\Types\ArrayType::class,
			'bigint'    => \RateHub\GraphQL\Doctrine\Types\BigIntType::class,
			'hstore'    => \RateHub\GraphQL\Doctrine\Types\HstoreType::class,
			'json'      => \RateHub\GraphQL\Doctrine\Types\JsonType::class
		];
		$option->em = $this->em;

		return new DoctrineProvider('TestProvider', $option);

	}

	public function getGraphQLSchema($provider){

		$queryType = new ObjectType([
			'name' => 'Query',
			'fields' => $provider->getQueries()
		]);
		$mutatorType = new ObjectType([
			'name' => 'Mutator',
			'fields' => $provider->getMutators()
		]);

		// Initialize the schema
		$schema = new Schema([
			'query' => $queryType,
			'mutation' => $mutatorType,
			'types' => $provider->getTypes()
		]);

		return $schema;

	}

	private function buildSchema() {

		$sm = $this->em->getConnection()->getSchemaManager();

		/* USER */
		$user = new Table("gql001_user");
		$user->addColumn('id', 'integer');
		$user->addColumn('created_at', 'datetime');
		$user->addColumn('big_int', 'bigint', [ 'notnull' => false ]);
		$user->addColumn('details', 'json_array', [ 'notnull' => false ]);
		$user->addColumn('key_values', 'hstore', [ 'notnull' => false ]);
		$user->setPrimaryKey(['id']);
		$sm->createTable($user);

		$user_sequence = new \Doctrine\DBAL\Schema\Sequence('gql001_user_id_seq');

		$sm->createSequence($user_sequence);


		/* PROJECT */
		$project = new Table("gql001_project");
		$project->addColumn('id', 'integer');
		$project->addColumn('user_id', 'integer');
		$project->setPrimaryKey(['id']);
		$project->addForeignKeyConstraint('gql001_user', ['user_id'], ['id']);
		$sm->createTable($project);

		$project_sequence = new \Doctrine\DBAL\Schema\Sequence('gql001_project_id_seq');
		$sm->createSequence($project_sequence);


		/* PROVINCE */
		$province = new Table("gql001_province");
		$province->addColumn('code', 'string');
		$province->setPrimaryKey(['code']);
		$sm->createTable($province);


		/* LOCATION */
		$location = new Table("gql001_location");
		$location->addColumn('lat', 'integer');
		$location->addColumn('long', 'integer');
		$location->addColumn('name', 'string');
		$location->setPrimaryKey(['lat', 'long']);
		$sm->createTable($location);

		/* SECTOR */
		$location = new Table("gql001_sector");
		$location->addColumn('id', 'string');
		$location->addColumn('num', 'integer');
		$location->setPrimaryKey(['id', 'num']);
		$sm->createTable($location);

		/* CITY */
		$city = new Table("gql001_city");
		$city->addColumn('id', 'integer');
		$city->addColumn('name', 'string');
		$city->addColumn('province', 'string');
		$city->addColumn('lat', 'integer');
		$city->addColumn('long', 'integer');
		$city->setPrimaryKey(['id']);
		$city->addForeignKeyConstraint('gql001_province', ['province'], ['code']);
		$city->addForeignKeyConstraint('gql001_location', ['lat', 'long'], ['lat', 'long']);
		$sm->createTable($city);

		$city_sequence = new \Doctrine\DBAL\Schema\Sequence('gql001_city_id_seq');
		$sm->createSequence($city_sequence);


		/* USER CITY */
		$usercity = new Table("gql001_usercity");
		$usercity->addColumn('user_id', 'integer');
		$usercity->addColumn('city_id', 'integer');
		$usercity->addForeignKeyConstraint('gql001_user', ['user_id'], ['id']);
		$usercity->addForeignKeyConstraint('gql001_city', ['city_id'], ['id']);
		$sm->createTable($usercity);


		/* USER SECTOR */
		$usersector = new Table("gql001_usersector");
		$usersector->addColumn('sector_id', 'string');
		$usersector->addColumn('sector_num', 'integer');
		$usersector->addColumn('user_id', 'integer');
		$usersector->addForeignKeyConstraint('gql001_user',		['user_id'],	['id']);
		$usersector->addForeignKeyConstraint('gql001_sector',	['sector_id', 'sector_num'],	['id', 'num']);
		$sm->createTable($usersector);


		/* INTEREST */
		$usercity = new Table("gql001_interest");
		$usercity->addColumn('id', 'integer');
		$usercity->addColumn('user_id', 'integer');
		$usercity->addForeignKeyConstraint('gql001_user', ['user_id'], ['id']);
		$sm->createTable($usercity);
		$interest_sequence = new \Doctrine\DBAL\Schema\Sequence('gql001_interest_id_seq');
		$sm->createSequence($interest_sequence);

	}

	private function dropSchema() {

		TestDb::dropTable($this->em, 'gql001_project');
		TestDb::dropTable($this->em, 'gql001_interest');
		TestDb::dropTable($this->em, 'gql001_usersector');
		TestDb::dropTable($this->em, 'gql001_usercity');
		TestDb::dropTable($this->em, 'gql001_user');
		TestDb::dropTable($this->em, 'gql001_city');
		TestDb::dropTable($this->em, 'gql001_province');
		TestDb::dropTable($this->em, 'gql001_location');
		TestDb::dropTable($this->em, 'gql001_sector');

		TestDb::dropSequence($this->em, 'gql001_project_id_seq');
		TestDb::dropSequence($this->em, 'gql001_user_id_seq');
		TestDb::dropSequence($this->em, 'gql001_city_id_seq');
		TestDb::dropSequence($this->em, 'gql001_interest_id_seq');

	}

	public function setupSampleData(){

		$em = $this->em;

		// USER 1
		$user = new GQL001_User();
		$user->created_at = \DateTime::createFromFormat('Y/m/d H:i:s', '2000/1/1 01:00:00', new \DateTimeZone('UTC'));  // timestamp: 946688400
		$user->big_int = 2500000000;

		$details = [
			'phone'     => '555-5555',
			'mobile'    => '555-6000'
		];

		$key_values = [
			'enabled'   => false,
			'greeting'  => 'Hello!'
		];

		$user->details      = $details;
		$user->key_values   = $key_values;

		$em->persist($user);
		$em->flush();

		// USER 2
		$user2 = new GQL001_User();
		$user2->created_at = \DateTime::createFromFormat('Y/m/d H:i:s', '2000/1/1 01:00:00', new \DateTimeZone('UTC'));  // timestamp: 946688400
		$user2->big_int = 2500000000;

		$details = [
			'phone'     => '555-6666',
			'mobile'    => '555-7000'
		];

		$key_values = [
			'enabled'   => false,
			'greeting'  => 'Hey!'
		];

		$user2->details      = $details;
		$user2->key_values   = $key_values;

		$em->persist($user2);
		$em->flush();



		// Generate Multiple interests
		$interests = [];
		for($i = 0; $i < 5; $i++) {

			$interest = new GQL001_Interest();
			$interest->user = $user;
			$em->persist($interest);

			array_push($interests, $interest);

		}
		$em->flush();

		// PROJECT 1
		$project = new GQL001_Project();
		$project->user = $user;
		$em->persist($project);
		$em->flush();

		// PROJECT 2
		$project2 = new GQL001_Project();
		$project2->user = $user2;
		$em->persist($project2);
		$em->flush();

		// PROVINCE
		$province = new GQL001_Province();
		$province->code = 'ON';
		$em->persist($province);

		$location = new GQL001_Location();
		$location->lat 	= 25;
		$location->long = 35;
		$location->name = 'here';
		$em->persist($location);

		$city = new GQL001_City();
		$city->name = 'Toronto';
		$city->province = $province;
		$city->location = $location;
		$em->persist($city);

		$user->cities->add($city);
		$city->users->add($user);

		$city2 = new GQL001_City();
		$city2->name = 'Vancouver';
		$city2->province = $province;
		$city2->location = $location;
		$em->persist($city2);

		$city3 = new GQL001_City();
		$city3->name = 'Ottawa';
		$city3->province = $province;
		$city3->location = $location;
		$em->persist($city3);

		$sector1 = new GQL001_Sector();
		$sector1->id 	= 'A';
		$sector1->num 	= 1;
		$em->persist($sector1);

		$sector2 = new GQL001_Sector();
		$sector2->id 	= 'B';
		$sector2->num 	= 2;
		$em->persist($sector2);

		$sector3 = new GQL001_Sector();
		$sector3->id 	= 'C';
		$sector3->num 	= 3;
		$em->persist($sector3);

		$em->flush();

		$em->clear();

	}

}
