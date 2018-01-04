<?php

namespace DoctrineGraph\Schema\GQL001;

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="User",
 *     description="A User",
 *	   include=true )
 */
class GQL001_User {

	/** @Id @Column(type="integer") @GeneratedValue
	 *	@GraphQLProperty(
	 * 		include=true)
	 */
	public $id;

    /**
     * @ManyToMany(targetEntity="GQL001_City", inversedBy="users")
     * @JoinTable(name="gql001_usercity",
     *     joinColumns={
     * 	        @JoinColumn(name="user_id", referencedColumnName="id")},
     * 	   inverseJoinColumns={
     *          @JoinColumn(name="city_id", referencedColumnName="id")})
     * 	@GraphQLProperty(
     * 		include=true)
     */
	public $cities;

	/**
	 * @ManyToMany(targetEntity="GQL001_Sector", inversedBy="users")
	 * @JoinTable(name="gql001_usersector",
	 *     joinColumns={
	 *     		@JoinColumn(name="user_id", referencedColumnName="id")},
	 * 	   inverseJoinColumns={
	 *     		 @JoinColumn(name="sector_id", referencedColumnName="id"),
	 *     		 @JoinColumn(name="sector_num", referencedColumnName="num")})
	 * 	@GraphQLProperty(
	 * 		include=true)
	 */
	public $sectors;


    /**
     * @OneToMany(targetEntity="GQL001_Interest", mappedBy="user")
     * @GraphQLProperty(
     *     include=true)
     */
    public $interests;

    /**
     * @Column(type="datetime")
     * @GraphQLProperty(
     *     include=true)
     */
	public $created_at;

    /**
     * @Column(type="bigint")
     * @GraphQLProperty(
     *     include=true)
     */
    public $big_int;

    /**
     * @Column(type="json_array")
     * @GraphQLProperty(
     *     include=true)
     */
    public $details;

    /**
     * @Column(type="hstore")
     * @GraphQLProperty(
     *     include=true)
     */
    public $key_values;


	public function __construct(){

        $this->cities       = new \Doctrine\Common\Collections\ArrayCollection();
        $this->interests    = new \Doctrine\Common\Collections\ArrayCollection();

    }
}