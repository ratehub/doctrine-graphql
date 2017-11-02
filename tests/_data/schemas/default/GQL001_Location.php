<?php

namespace DoctrineGraph\Schema\GQL001;

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="Location",
 *     description="A Location",
 *	   include=true )
 */
class GQL001_Location {

	/** @Id @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $lat;

	/** @Id @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $long;

	/** @Column(type="string")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $name;

	/** @OneToMany(targetEntity="GQL001_City", mappedBy="location")
	 *  @GraphQLProperty(
	 * 		include=true)
	 */
	public $cities;

	public function __construct(){


	}

}