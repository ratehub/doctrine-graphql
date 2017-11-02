<?php

namespace DoctrineGraph\Schema\GQL001;

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="City",
 *     description="A City",
 *	   include=true )
 */
class GQL001_City {

    /** @Id @Column(type="integer") @GeneratedValue
     *	@GraphQLProperty(
     * 		include=true)
     */
    public $id;

    /** @Column(type="string")
     *	@GraphQLProperty(
     * 		include=true)
     */
    public $name;

    /** @ManyToMany(targetEntity="GQL001_User", mappedBy="cities")
     *	@GraphQLProperty(
     * 		include=true)
     */
    public $users;

    /**
     * @ManyToOne (targetEntity="GQL001_Province", inversedBy="cities")
     * @JoinColumn(name="province", referencedColumnName="code"))
     * @GraphQLProperty(
     *      include = true)
     */
    public $province;

	/** @ManyToOne(targetEntity="GQL001_Location", inversedBy="cities")
	 *  @JoinColumns({
	 *     @JoinColumn(name="lat", referencedColumnName="lat"),
	 *     @JoinColumn(name="long", referencedColumnName="long")
	 *  })
	 *  @GraphQLProperty(
	 *     include=true)
	 */
    public $location;

	/** @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
    public $lat;

	/** @Column(type="integer")
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
    public $long;


    public function __construct(){

        $this->users = new \Doctrine\Common\Collections\ArrayCollection();

    }

}