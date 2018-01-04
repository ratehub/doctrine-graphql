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

class CreateMutatorCest {

	public $factory;

	public function _before() {

		if ($this->factory === null)
			$this->factory = new TestFactoryDefault();

		$this->factory->reset();

	}

	/**
	 * Scenario:
	 * Create a record with the mutator
	 *
	 * @param UnitTester $I
	 */
	public function createInterestWithExistingUser (UnitTester $I){

		$I->wantTo('Create an interest record linked to an existing user');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData();

		$schema = $this->factory->getGraphQLSchema($provider);

		$interests = [[
			"user" => ["id" => 1]
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation CreateInterest($items: [Interest__Input]){
			  create_Interest(items: $items){
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

		$I->assertEquals(1, count($result["data"]["create_Interest"]));

		$interest = $result["data"]["create_Interest"][0];

		$I->assertEquals(1, $interest["user"]["id"]);

	}

	/**
	 * Scenario:
	 * Create a record with the mutator
	 *
	 * @param UnitTester $I
	 */
	public function createLocation (UnitTester $I){

		$I->wantTo('Create a location');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$locations = [[
			"lat" => 20,
			"long" => 25,
			"name" => "test"
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation CreateLocation($items: [Location__Input]){
			  create_Location(items: $items){
			  	lat
			  	long
			  }
			}',
			null,
			new GraphContext(),
			['items' => $locations]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["create_Location"]));

		$location = $result["data"]["create_Location"][0];

		$I->assertEquals(20, $location["lat"]);
		$I->assertEquals(25, $location["long"]);

	}

	/**
	 * Scenario:
	 * Create a user record with the mutator and relate it to multiple existing
	 * sectors. Tests to Many-To-Many association in the create mutator
	 *
	 * @param UnitTester $I
	 */
	public function createUserWithMultipleExistingSectors (UnitTester $I){

		$I->wantTo('Create an user record linked to multiple sectors (composite key)');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$users = [
			[
				"created_at" => 946688400, // 2000/1/1 01:00:00 UTC
				"sectors" => [
					["id" => "A", "num" => 1],
					["id" => "B", "num" => 2]
				]
			]
		];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation CreateUser($items: [User__Input]){
			  create_User(items: $items){
			  	id
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
			['items' => $users]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(1, count($result["data"]["create_User"]));

		$users = $result["data"]["create_User"][0];

		$I->assertEquals(3, $users["id"]);

		$sectors = $users["sectors"]["items"];

		$I->assertEQuals(2, count($sectors));

	}

}


