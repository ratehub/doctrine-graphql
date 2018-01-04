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

class DeleteMutatorCest {

	public $factory;

	public function _before() {

		if ($this->factory === null)
			$this->factory = new TestFactoryDefault();

		$this->factory->reset();

	}

	/**
	 * Scenario:
	 * Delete a record with the mutator
	 *
	 * @param UnitTester $I
	 */
	public function deleteLocation (UnitTester $I){

		$I->wantTo('Delete a location');

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
		]];

		$result = \GraphQL\GraphQL::execute(
			$schema,
			'mutation DeleteLocation($items: [Location__Input]){
			  delete_Location(items: $items)
			}',
			null,
			new GraphContext(),
			['items' => $locations]
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result));

		$I->assertEquals(true, $result["data"]["delete_Location"]);

	}

}