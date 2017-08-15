<?php

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="Project",
 *     description="A Project",
 *	   include=true )
 */
class GQL001_Project {

	/** @Id @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $id;

	/** @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $user_id;

	/** @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $user;

}