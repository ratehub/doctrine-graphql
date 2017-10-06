<?php

namespace DoctrineGraph\Schema\GQL001;

/**
 * @Entity
 * @Table
 * @GraphQLType(
 *     name="Interest",
 *     description="An Interest",
 *	   include=true )
 */
class GQL001_Interest
{

    /** @Id @Column(type="integer") @GeneratedValue
     *	@GraphQLProperty(
     * 	    include=true)
     */
    public $id;

    /**
     * @ManyToOne (targetEntity="GQL001_User")
     * @GraphQLProperty(
     *      include = true)
     */
    public $user;

}