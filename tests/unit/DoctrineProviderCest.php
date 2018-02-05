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

class DoctrineProviderCest
{

	public $factory;

	public function _before(){

		if($this->factory === null)
			$this->factory = new TestFactoryDefault();

		$this->factory->reset();

	}

	/**
	 * Scenario:
	 * Filter = blacklist
	 *
	 * Instantiate the provider and verify the schema
	 *
	 * @param UnitTester $I
	 */
	public function instantiateProvider (UnitTester $I){

        $I->wantTo('Instantiate DoctrineProvider');

        $provider = $this->factory->getProvider();

        $types = $provider->getTypes();

        $I->assertEquals(19, count($types));

        $userType = $provider->getType('User');

        $I->assertNotNull($userType);
        $I->assertEquals("User", $userType->name); // Test the name annotation property
		$I->assertEquals("A User", $userType->description); // Test the description annotation property

        $projectType = $provider->getType('Project');

        $I->assertNotNull($projectType);
		$I->assertEquals("Project", $projectType->name); // Test the name annotation property
		$I->assertEquals("A Project", $projectType->description); // Test the description annotation property

        $schema = $this->factory->getGraphQLSchema($provider);

		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  __schema{
				types{
				  name
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$types = array();
		foreach($result['data']['__schema']['types'] as $type){
			array_push($types, $type["name"]);
		}

		$I->assertContains('User', $types);
		$I->assertContains('User__List', $types);
		$I->assertContains('Project', $types);
		$I->assertContains('Project__List', $types);

		$result2 = \GraphQL\GraphQL::execute(
			$schema,
			"query testQuery{
				User{
					items{
						id
					}
				}	
			}",
			null,
			new GraphContext(),
			null
		);

		$I->assertEquals(0, count($result2["data"]["User"]["items"]));

     }

}