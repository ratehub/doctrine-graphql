<?php

namespace DoctrineGraph\Schema\GQL001;

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="Project",
 *     description="A Project",
 *	   include=true )
 */
class GQL001_Project {

	/** @Id @Column(type="integer") @GeneratedValue
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $id;

    /** @OneToOne(targetEntity="GQL001_User")
	 *  @GraphQLProperty(
	 * 		include=true)
	 */
	public $user;

}