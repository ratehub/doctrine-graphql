<?php

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;

class TestDb
{

    public static function createEntityManager($path){

        $paths = array($path);
        $isDevMode = false;

        // the connection configuration
        $dbParams = array(
            'driver'   => 'pdo_pgsql',
            'user'     => 'testuser',
            'password' => 'aeqeacadq',
            'dbname'   => 'testdb',
            'host'     => 'localhost'
        );

        $config = Setup::createAnnotationMetadataConfiguration($paths, $isDevMode);

        $entityManager = EntityManager::create($dbParams, $config);

        return $entityManager;

    }

}