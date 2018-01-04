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

class QueryCest {

	public $factory;

	public function _before() {

		if ($this->factory === null)
			$this->factory = new TestFactoryDefault();

		$this->factory->reset();

	}

	/**
	 * Scenario:
	 * Query for project and include the
	 *
	 * @param UnitTester $I
	 */
	public function queryUser (UnitTester $I){

		$I->wantTo('Query for User');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  User{
				items{
				  id
				  created_at
				  big_int
				  details
				  key_values
				  interests{
				    items{
				      id
				    }
				  }
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(2, count($result["data"]["User"]["items"]));

		$item = $result["data"]["User"]["items"][0];

		$I->assertEquals(1, $item['id']);
		$I->assertEquals(946688400, $item['created_at']);
		$I->assertSame("2500000000" , $item['big_int']); // Should come back as a string
		$I->assertEquals('555-5555', $item['details']['phone']);
		$I->assertEquals('Hello!', $item['key_values']->greeting);
		$I->assertEquals(5, count($item['interests']['items']));

	}

	// Query Project
	public function queryProject (UnitTester $I){

		$I->wantTo('Query for Project');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Project{
				items{
				  id
				  user{
				    id
				    cities{
				      items{
				        id
				        name
				      }
				    }
				  }
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(2, count($result["data"]["Project"]["items"]));

		$item = $result["data"]["Project"]["items"][0];

		$I->assertEquals(1, $item['id']);

		$I->assertEquals("Toronto", $item['user']['cities']['items'][0]['name']);

	}

	public function queryCityUsers(UnitTester $I){

		$I->wantTo('Query for a city\'s users');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a cities list of users
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  City{
				items{
				  id
				  users{
				    items{
				      id
				      created_at
				    }  	
				  }
				  province{
				    code
				  }
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(3, count($result["data"]["City"]["items"]));

		$item = $result["data"]["City"]["items"][0];

		$I->assertEquals(1, $item['id']);

		$I->assertEquals('ON', $item['province']['code']);

		$I->assertEquals(1, count($item['users']['items']));

		$I->assertEquals(1, $item['users']['items'][0]['id']);


	}

	public function queryProvinceWithCities(UnitTester $I){

		$I->wantTo('Query for a province\'s cities');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Province{
				items{
				  code
				  cities{
				    items{
				      id
				      name
				      province {
				        code
				      }
				    }  	
				  }
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["Province"]["items"]));

		$province = $result["data"]["Province"]["items"][0];

		$I->assertEquals('ON', $province['code']);

		$I->assertEquals(3, count($province['cities']['items']));

		$city = $province['cities']['items'][0];

		$I->assertEquals('ON', $city['province']['code']);

	}

	public function queryLocation(UnitTester $I){

		$I->wantTo('Query for a location');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Location{
				items{
				  lat
				  long
				  cities{
				    items{
				      id
				      name
				      province {
				        code
				      }
				      location{
				        lat,
				        long
				        cities{
				          items{
				            id
				            name
				          }
				        }
				      }
				    }  	
				  }
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["Location"]["items"]));

		$location = $result["data"]["Location"]["items"][0];

		$I->assertEquals(3, count($location['cities']['items']));

		$city = $location['cities']['items'][0];

		$I->assertEquals(25, $city['location']['lat']);
		$I->assertEquals(35, $city['location']['long']);

	}

	private function setupStringFilterData($provider){

		$em = $provider->getManager();

		$location = new GQL001_Location();
		$location->lat 	= 25;
		$location->long = 35;
		$location->name = 'Kingston';
		$em->persist($location);

		$location2 = new GQL001_Location();
		$location2->lat  = 26;
		$location2->long = 36;
		$location2->name = 'Toronto';
		$em->persist($location2);

		$location3 = new GQL001_Location();
		$location3->lat  = 27;
		$location3->long = 37;
		$location3->name = 'Ottawa';
		$em->persist($location3);

		$location4 = new GQL001_Location();
		$location4->lat  = 28;
		$location4->long = 38;
		$location4->name = 'Ottawa City';
		$em->persist($location4);

		$em->flush();

	}

	public function stringFilterIn(UnitTester $I){

		$I->wantTo('Query string with in filter');

		$provider = $this->factory->getProvider();

		$this->setupStringFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Location( name: { in: [\"Kingston\"] } ){
				items{
				  lat
				  long
				  name
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["Location"]["items"]));

		$I->assertEquals('Kingston', $result["data"]["Location"]["items"][0]['name']);

	}

	public function stringFilterEquals(UnitTester $I){

		$I->wantTo('Query string with equals filter');

		$provider = $this->factory->getProvider();

		$this->setupStringFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Location( name: { equals: \"Kingston\" } ){
				items{
				  lat
				  long
				  name
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["Location"]["items"]));

		$I->assertEquals('Kingston', $result["data"]["Location"]["items"][0]['name']);

	}

	public function stringFilterContains(UnitTester $I){

		$I->wantTo('Query string with contains filter');

		$provider = $this->factory->getProvider();

		$this->setupStringFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Location( name: { contains: \"ngst\" } ){
				items{
				  lat
				  long
				  name
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["Location"]["items"]));

		$I->assertEquals('Kingston', $result["data"]["Location"]["items"][0]['name']);

	}

	public function stringFilterStartsWith(UnitTester $I){

		$I->wantTo('Query string with starts with filter');

		$provider = $this->factory->getProvider();

		$this->setupStringFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Location( name: { startsWith: \"Ottawa\" } ){
				items{
				  lat
				  long
				  name
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(2, count($result["data"]["Location"]["items"]));

		$locations = $result["data"]["Location"]["items"];

		$ottawaFound 		= false;
		$ottawaCityFound 	= false;
		foreach($locations as $location){
			if($location['name'] === "Ottawa")
				$ottawaFound = true;
			if($location['name'] === "Ottawa City")
				$ottawaCityFound = true;
		}

		$I->assertEquals(true, $ottawaFound && $ottawaCityFound);

	}

	public function stringFilterEndsWith(UnitTester $I){

		$I->wantTo('Query string with ends with filter');

		$provider = $this->factory->getProvider();

		$this->setupStringFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Location( name: { endsWith: \"onto\" } ){
				items{
				  lat
				  long
				  name
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["Location"]["items"]));

		$I->assertEquals('Toronto', $result["data"]["Location"]["items"][0]['name']);

	}

	private function setupDateTimeFilterData($provider){

		$em = $provider->getManager();

		$user1 = new GQL001_User();
		$user1->created_at = \DateTime::createFromFormat('Y/m/d H:i:s', '2001/1/1 01:00:00', new \DateTimeZone('UTC'));  // timestamp: 946688400
		$em->persist($user1);

		$user2 = new GQL001_User();
		$user2->created_at = \DateTime::createFromFormat('Y/m/d H:i:s', '2002/1/1 01:00:00', new \DateTimeZone('UTC'));  // timestamp: 946688400
		$em->persist($user2);

		$user3 = new GQL001_User();
		$user3->created_at = \DateTime::createFromFormat('Y/m/d H:i:s', '2003/1/1 01:00:00', new \DateTimeZone('UTC'));  // timestamp: 946688400
		$em->persist($user3);

		$user4 = new GQL001_User();
		$user4->created_at = \DateTime::createFromFormat('Y/m/d H:i:s', '2004/1/1 01:00:00', new \DateTimeZone('UTC'));  // timestamp: 946688400
		$em->persist($user4);

		$em->flush();

	}

	public function stringFilterDateEquals(UnitTester $I){

		$I->wantTo('Query date equals filter');

		$provider = $this->factory->getProvider();

		$this->setupDateTimeFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$date1 = \DateTime::createFromFormat('Y/m/d H:i:s', '2001/1/1 01:00:00', new \DateTimeZone('UTC'));

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  User( created_at: { equals: \"" . $date1->getTimestamp(). "\" } ){
				items{
				  created_at
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["User"]["items"]));

		$I->assertEquals($date1->getTimestamp(), $result["data"]["User"]["items"][0]['created_at']);

	}

	public function stringFilterDateGreater(UnitTester $I){

		$I->wantTo('Query date greater filter');

		$provider = $this->factory->getProvider();

		$this->setupDateTimeFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$date1 = \DateTime::createFromFormat('Y/m/d H:i:s', '2003/1/1 01:00:00', new \DateTimeZone('UTC'));

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  User( created_at: { greater: \"" . $date1->getTimestamp(). "\" } ){
				items{
				  created_at
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["User"]["items"]));

	}

	public function stringFilterDateLess(UnitTester $I){

		$I->wantTo('Query date less filter');

		$provider = $this->factory->getProvider();

		$this->setupDateTimeFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$date1 = \DateTime::createFromFormat('Y/m/d H:i:s', '2003/1/1 01:00:00', new \DateTimeZone('UTC'));

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  User( created_at: { less: \"" . $date1->getTimestamp(). "\" } ){
				items{
				  created_at
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(2, count($result["data"]["User"]["items"]));

	}

	public function stringFilterDateGreaterOrEqual(UnitTester $I){

		$I->wantTo('Query date greater or equals filter');

		$provider = $this->factory->getProvider();

		$this->setupDateTimeFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$date1 = \DateTime::createFromFormat('Y/m/d H:i:s', '2003/1/1 01:00:00', new \DateTimeZone('UTC'));

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  User( created_at: { greaterOrEquals: \"" . $date1->getTimestamp(). "\" } ){
				items{
				  created_at
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(2, count($result["data"]["User"]["items"]));

	}

	public function stringFilterDateLessOrEqual(UnitTester $I){

		$I->wantTo('Query date less or equals filter');

		$provider = $this->factory->getProvider();

		$this->setupDateTimeFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$date1 = \DateTime::createFromFormat('Y/m/d H:i:s', '2003/1/1 01:00:00', new \DateTimeZone('UTC'));

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  User( created_at: { lessOrEquals: \"" . $date1->getTimestamp(). "\" } ){
				items{
				  created_at
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(3, count($result["data"]["User"]["items"]));

	}

	public function stringFilterDateBetween(UnitTester $I){

		$I->wantTo('Query date between filter');

		$provider = $this->factory->getProvider();

		$this->setupDateTimeFilterData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		$date1 = \DateTime::createFromFormat('Y/m/d H:i:s', '2002/1/1 01:00:00', new \DateTimeZone('UTC'));
		$date2 = \DateTime::createFromFormat('Y/m/d H:i:s', '2003/1/1 01:00:00', new \DateTimeZone('UTC'));

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  User( created_at: { between: { from: \"" . $date1->getTimestamp(). "\", to: \"" . $date2->getTimestamp() . "\" } } ){
				items{
				  created_at
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(2, count($result["data"]["User"]["items"]));

	}

	public function toOneAssociationFilter(UnitTester $I){

		$I->wantTo('Query and filter by a to-one association');

		$provider = $this->factory->getProvider();

		$this->factory->setupSampleData($provider);

		$schema = $this->factory->getGraphQLSchema($provider);

		// Retrieve a province with a list of cities
		$result = \GraphQL\GraphQL::execute(
			$schema,
			"{
			  Project( user: { id: 2 } ){
				items{
				  id
				}
			  }
			}",
			null,
			new GraphContext(),
			null
		);

		$provider->clearBuffers();

		$I->assertEquals(1, count($result["data"]["Project"]["items"]));

		$project = $result["data"]["Project"]["items"][0];

		$I->assertEQuals(2, $project["id"]);


	}

}