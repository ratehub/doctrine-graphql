<?php

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use RateHub\GraphQL\Doctrine\AnnotationLoader;
use Doctrine\Common\Annotations\AnnotationRegistry;

class TestDb
{

    public static function createEntityManager($schema){

		$path = './tests/_data/schemas/' . strtolower($schema);

        $paths = array($path);
        $isDevMode = true;

        // the connection configuration
        $dbParams = array(
            'driver'   => 'pdo_pgsql',
            'user'     => 'testuser',
            'password' => 'aeqeacadq',
            'dbname'   => 'testdb',
            'host'     => 'localhost'
        );

        $arrayCache = new \Doctrine\Common\Cache\ArrayCache;

        $config = Setup::createAnnotationMetadataConfiguration($paths, $isDevMode);

        $config->setQueryCacheImpl($arrayCache);
        $config->setResultCacheImpl($arrayCache);
        $config->setMetadataCacheImpl($arrayCache);

        $entityManager = EntityManager::create($dbParams, $config);

		AnnotationRegistry::registerLoader(array(new AnnotationLoader(), "load"));

        return $entityManager;

    }

	public static function dropTable($em, $table){

		$checkSql = "SELECT * FROM information_schema.tables WHERE table_name='" . $table . "'";
		$checkResult = $em->getConnection()->executeQuery($checkSql);

		if ($checkResult->fetch()) {
			$em->getConnection()->executeUpdate('DROP TABLE ' . $table);
		}

	}

	public static function dropSequence($em, $sequence){

		$checkSql = "SELECT * FROM information_schema.sequences WHERE sequence_name='" . $sequence . "'";
		$checkResult = $em->getConnection()->executeQuery($checkSql);

		if ($checkResult->fetch()) {
			$em->getConnection()->executeUpdate('DROP SEQUENCE ' . $sequence);
		}

	}

}