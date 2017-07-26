<?php

use RateHub\GraphQL\Doctrine\DoctrineProvider;
use RateHub\GraphQL\Doctrine\DoctrineProviderOptions;

class DoctrineProviderCest
{

    public function case1 (UnitTester $I){



        $I->wantTo('Instantiate DoctrineProvider');

        $option = new DoctrineProviderOptions();
        $option->em = TestDb::createEntityManager('./tests/_data/schemas/default');

        $provider = new DoctrineProvider('TestProvider', $option);

    }


}