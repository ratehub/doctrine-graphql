<?php

use RateHub\GraphQL\GraphContext;


class CustomConfigCest {

    public $factory;

    public function _before() {

        $options = [
            'ProxyNamespace' => 'MyProject\Proxies'
        ];

        if ($this->factory === null)
            $this->factory = new TestFactoryDefault($options);

        $this->factory->reset();

    }

    public function queryLocationWithCustomProxyNamespace(UnitTester $I){

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
}
