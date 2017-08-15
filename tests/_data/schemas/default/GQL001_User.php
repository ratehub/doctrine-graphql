<?php

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="User",
 *     description="A User",
 *	   include=true )
 */
class GQL001_User {

	/** @Id @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $id;

}