<?php

interface TestFactory{

	public function reset();

	public function getEntityManager();

	public function getProvider();

	public function getGraphQLSchema($provider);

}
