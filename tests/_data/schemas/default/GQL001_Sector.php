<?php

namespace DoctrineGraph\Schema\GQL001;

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="Sector",
 *     description="A Sector",
 *	   include=true )
 */
class GQL001_Sector {

	/** @Id @Column(type="string")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $id;

	/** @Id @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $num;

	/** @ManyToMany(targetEntity="GQL001_User", mappedBy="sectors")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $users;


	public function __construct(){


	}

}