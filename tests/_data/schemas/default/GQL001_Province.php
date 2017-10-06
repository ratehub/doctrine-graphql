<?php

namespace DoctrineGraph\Schema\GQL001;

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="Province",
 *     description="A Province",
 *	   include=true )
 */
class GQL001_Province
{

    /** @Id @Column(type="string")
     *	@GraphQLProperty(
     * 		include=true)
     */
    public $code;


    /**
     * @OneToMany(targetEntity="GQL001_City", mappedBy="province")
     * @GraphQLProperty(
     *     include=true)
     */
    public $cities;

}