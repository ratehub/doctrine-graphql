<?php

use RateHub\GraphQL\GraphContext;
use RateHub\GraphQL\Doctrine\DoctrineProvider;
use RateHub\GraphQL\Doctrine\DoctrineProviderOptions;

use Doctrine\DBAL\Schema\Table;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Schema;

use DoctrineGraph\Schema\GQL001\GQL001_Project;
use DoctrineGraph\Schema\GQL001\GQL001_User;
use DoctrineGraph\Schema\GQL001\GQL001_City;
use DoctrineGraph\Schema\GQL001\GQL001_Interest;
use DoctrineGraph\Schema\GQL001\GQL001_Province;
use DoctrineGraph\Schema\GQL001\GQL001_Location;
use DoctrineGraph\Schema\GQL001\GQL001_Sector;

use Doctrine\DBAL\Types\Type;

class UpdateMutatorCest {

	public $factory;

	public function _before() {

		if ($this->factory === null)
			$this->factory = new TestFactoryDefault();

		$this->factory->reset();

	}

	/**
	 * Scenario:
	 * Update a record with the mutator
	 *
	 * @param UnitTester $I
	 */
	public function updateLocation (UnitTester $I){

		$I->wantTo('Update a location');

		$em = $this->factory->getEntityManager();

		$provider = $this->factory->getProvider();

		$location = new GQL001_Location();
		$location->lat 	= 25;
		$location->long = 35;
		$location->name = 'here';
		$em->persist($location);
		$em->flush();

		$schema = $this->factory->getGraphQLSchema($provider);

		$locations = [[
			"lat" => 25,
			"long" => 35,
			"name" => "updatedname"
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation UpdateLocation($items: [Location__Input]){
			  update_Location(items: $items){
			  	lat
			  	long
			  	name
			  }
			}',
			null,
			new GraphContext(),
			['items' => $locations]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["update_Location"]));

		$location = $result["data"]["update_Location"][0];

		$I->assertEquals(25, $location["lat"]);
		$I->assertEquals(35, $location["long"]);
		$I->assertEquals("updatedname", $location["name"]);

	}

	/**
	 * Scenario:
	 * Update an Interest record with linking to an existing record. Tests Many-to-one relationship
	 *
	 * @param UnitTester $I
	 */
	public function updateInterestWithExistingUser (UnitTester $I){

		$I->wantTo('Update an interest record linked to an existing user');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$interests = [[
			"id" => 1,
			"user" => ["id" => 2]
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation UpdateInterest($items: [Interest__Input]){
			  update_Interest(items: $items){
			  	id,
			  	user{
			  		id
			  	}
			  }
			}',
			null,
			new GraphContext(),
			['items' => $interests]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["update_Interest"]));

		$interest = $result["data"]["update_Interest"][0];

		$I->assertEquals(2, $interest["user"]["id"]);

	}

	/**
	 * Scenario:
	 * Test the update mutator with a many to one relationship defined by a composite key
	 *
	 * @param UnitTester $I
	 */
	public function updateCityWithExistingLocation (UnitTester $I){

		$I->wantTo('Update an city record linked to an existing location (composite key)');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$em = $this->factory->getEntityManager();

		$location = new GQL001_Location();
		$location->lat 	= 15;
		$location->long = 45;
		$location->name = 'here2';
		$em->persist($location);
		$em->flush();

		$schema = $this->factory->getGraphQLSchema($provider);

		$cities = [[
			"id" => 1,
			"location" => ["lat" => 15, "long" => 45]
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation UpdateCity($items: [City__Input]){
			  update_City(items: $items){
			  	id,
			  	location{
			  		lat
			  		long
			  	}
			  }
			}',
			null,
			new GraphContext(),
			['items' => $cities]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["update_City"]));

		$city = $result["data"]["update_City"][0];

		$I->assertEquals(15, $city["location"]["lat"]);

	}

	/**
	 * Scenario:
	 * Add multiple existing cities to a user. Testing the n-to-many mutators
	 *
	 * @param UnitTester $I
	 */
	public function updateUserAddMultipleExistingCities (UnitTester $I){

		$I->wantTo('Update user add multiple existing cities');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$users = [[
			"id" => 1,
			"cities" => [
				["id" => 1],
				["id" => 2],
				["id" => 3]
			]
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation UpdateUser($items: [User__Input]){
			  update_User(items: $items){
			  	id,
			  	cities{
			  	  items{
			  		id
			  		name
				  }
			  	}
			  }
			}',
			null,
			new GraphContext(),
			['items' => $users]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["update_User"]));

		$userCities = $result["data"]["update_User"][0];

		$I->assertEquals(1, $userCities["id"]);

		$I->assertEquals(3, count($userCities["cities"]["items"]));

		// Verify the database operation was successful

		$pdo = $provider->getManager()->getConnection()->getWrappedConnection();

		$dbResult = $pdo->query('SELECT * FROM gql001_usercity');

		$dbResult->setFetchMode(\Doctrine\DBAL\Driver\PDOConnection::FETCH_ASSOC);

		$rows = $dbResult->fetchAll();

		$I->assertEquals(3, count($rows));


	}

	/**
	 * Scenario:
	 * Remove multiple existing cities from a user. Testing the n-to-many mutators
	 *
	 * @param UnitTester $I
	 */
	public function updateUserRemoveMultipleExistingCities (UnitTester $I){

		$I->wantTo('Update user remove existing related cities');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$em = $this->factory->getEntityManager();

		$user = $em->getRepository(GQL001_User::class)->find(1);

		$city2 = $em->getRepository(GQL001_City::class)->find(2);
		$city3 = $em->getRepository(GQL001_City::class)->find(3);

		$user->cities->add($city2);
		$user->cities->add($city3);

		$em->flush($user);

		$schema = $this->factory->getGraphQLSchema($provider);

		$users = [[
			"id" => 1,
			"cities" => [
				["id" => 1],
				["id" => 3]
			]
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation UpdateUser($items: [User__Input]){
			  update_User(items: $items){
			  	id,
			  	cities{
			  	  items{
			  		id
			  		name
				  }
			  	}
			  }
			}',
			null,
			new GraphContext(),
			['items' => $users]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["update_User"]));

		$userCities = $result["data"]["update_User"][0];

		$I->assertEquals(1, $userCities["id"]);

		$I->assertEquals(2, count($userCities["cities"]["items"]));

		// Verify the database operation was successful

		$pdo = $provider->getManager()->getConnection()->getWrappedConnection();

		$dbResult = $pdo->query('SELECT * FROM gql001_usercity');

		$dbResult->setFetchMode(\Doctrine\DBAL\Driver\PDOConnection::FETCH_ASSOC);

		$rows = $dbResult->fetchAll();

		$I->assertEquals(2, count($rows));


	}

	/**
	 * Scenario:
	 * Add multiple existing cities to a user. Testing the n-to-many mutators
	 * with a entity having a composite identifier.
	 *
	 * @param UnitTester $I
	 */
	public function updateUserAddMultipleExistingSectors (UnitTester $I){

		$I->wantTo('Update user add multiple existing sectors (composite key)');

		$em = $this->factory->getEntityManager();

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$sectors = [[
			"id" => 1,
			"sectors" => [
				["id" => 'A', "num" => 1],
				["id" => 'B', "num" => 2],
				["id" => 'C', "num" => 3]
			]
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation UpdateUser($items: [User__Input]){
			  update_User(items: $items){
			  	id,
			  	sectors{
			  	  items{
			  		id
			  		num
				  }
			  	}
			  }
			}',
			null,
			new GraphContext(),
			['items' => $sectors]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["update_User"]));

		$userSectors = $result["data"]["update_User"][0];

		$I->assertEquals(1, $userSectors["id"]);

		$I->assertEquals(3, count($userSectors["sectors"]["items"]));

		// Verify the database operation was successful

		$pdo = $provider->getManager()->getConnection()->getWrappedConnection();

		$dbResult = $pdo->query('SELECT * FROM gql001_usersector');

		$dbResult->setFetchMode(\Doctrine\DBAL\Driver\PDOConnection::FETCH_ASSOC);

		$rows = $dbResult->fetchAll();

		$I->assertEquals(3, count($rows));


	}

	/**
	 * Scenario:
	 * Remove multiple existing cities to a user. Testing the n-to-many mutators
	 * with a entity having a composite identifier.
	 *
	 * @param UnitTester $I
	 */
	public function updateUserRemoveMultipleExistingSectors (UnitTester $I){

		$I->wantTo('Update user remove multiple existing sectors (composite key)');

		$em = $this->factory->getEntityManager();

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$user = $em->getRepository(GQL001_User::class)->find(1);

		$sector1 = $em->getRepository(GQL001_Sector::class)->find(['id' => 'A', 'num' => 1]);
		$sector2 = $em->getRepository(GQL001_Sector::class)->find(['id' => 'B', 'num' => 2]);
		$sector3 = $em->getRepository(GQL001_Sector::class)->find(['id' => 'C', 'num' => 3]);

		$user->sectors->add($sector1);
		$user->sectors->add($sector2);
		$user->sectors->add($sector3);

		$em->flush();

		$schema = $this->factory->getGraphQLSchema($provider);

		$sectors = [[
			"id" => 1,
			"sectors" => [
				["id" => 'A', "num" => 1],
				["id" => 'C', "num" => 3]
			]
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation UpdateUser($items: [User__Input]){
			  update_User(items: $items){
			  	id,
			  	sectors{
			  	  items{
			  		id
			  		num
				  }
			  	}
			  }
			}',
			null,
			new GraphContext(),
			['items' => $sectors]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["update_User"]));

		$userSectors = $result["data"]["update_User"][0];

		$I->assertEquals(1, $userSectors["id"]);

		$I->assertEquals(2, count($userSectors["sectors"]["items"]));

		// Verify the database operation was successful

		$pdo = $provider->getManager()->getConnection()->getWrappedConnection();

		$dbResult = $pdo->query('SELECT * FROM gql001_usersector');

		$dbResult->setFetchMode(\Doctrine\DBAL\Driver\PDOConnection::FETCH_ASSOC);

		$rows = $dbResult->fetchAll();

		$I->assertEquals(2, count($rows));


	}

}