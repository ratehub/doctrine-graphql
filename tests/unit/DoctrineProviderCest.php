<?php

use RateHub\GraphQL\GraphContext;
use RateHub\GraphQL\Doctrine\DoctrineProvider;
use RateHub\GraphQL\Doctrine\DoctrineProviderOptions;

use Doctrine\DBAL\Schema\Table;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Schema;

class DoctrineProviderCest
{

	public function _before(){

		$em = TestDb::createEntityManager('./tests/_data/schemas/default');

		$this->_dropTable($em, 'gql001_project');
		$this->_dropTable($em, 'gql001_user');

		$em->clear();

	}

	public function _dropTable($em, $table){

		$checkSql = "SELECT * FROM information_schema.tables WHERE table_name='" . $table . "'";
		$checkResult = $em->getConnection()->executeQuery($checkSql);

		if ($checkResult->fetch()) {
			$em->getConnection()->executeUpdate('DROP TABLE ' . $table);
		}

	}

	/**
	 * Scenario:
	 * Filter = blacklist
	 *
	 * @param UnitTester $I
	 */
	public function case1 (UnitTester $I){

        $I->wantTo('Instantiate DoctrineProvider');

        $option = new DoctrineProviderOptions();
        $option->em = TestDb::createEntityManager('./tests/_data/schemas/default');

		$sm = $option->em->getConnection()->getSchemaManager();

		$user = new Table("gql001_user");
		$user->addColumn('id', 'integer');
		$user->setPrimaryKey(['id']);

		$sm->createTable($user);

		$project = new Table("gql001_project");
		$project->addColumn('id', 'integer');
		$project->addColumn('user_id', 'integer');
		$project->addColumn('user', 'string');
		$project->setPrimaryKey(['id']);
		$project->addForeignKeyConstraint('gql001_user', ['user_id'], ['id']);

		$sm->createTable($project);

        $provider = new DoctrineProvider('TestProvider', $option);

        $types = $provider->getTypes();

        $I->assertEquals(3, count($types));

        $userType = $provider->getType('GQL001_User');

        $I->assertNotNull($userType);
        $I->assertEquals("User", $userType->name); // Test the name annotation property
		$I->assertEquals("A User", $userType->description); // Test the description annotation property

        $projectType = $provider->getType('GQL001_Project');

        $I->assertNotNull($projectType);
		$I->assertEquals("Project", $projectType->name); // Test the name annotation property
		$I->assertEquals("A Project", $projectType->description); // Test the description annotation property

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