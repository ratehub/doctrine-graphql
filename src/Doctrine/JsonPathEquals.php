<?php

namespace RateHub\GraphQL\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Class JsonPathEquals
 *
 * Doctrine query builder function. Generates the SQL
 * necessary on the left side of a comparison for a json path.
 *
 * For example given a value stored in test_column : {foo : { bar : true } }
 *
 * SQL needed would be CAST( test_column -> foo ->> bar as boolean)
 *
 * The final query would include  CAST( test_column -> foo ->> bar as boolean) = true
 *
 * @package RateHub\GraphQL\Doctrine
 */
class JsonPathEquals extends FunctionNode
{

	/**
	 * @var string Column where the json data is stored
	 */
	public $stringColumn;

	/**
	 * @var string The json path that we would like to compare a value to
	 */
    public $stringJsonPath;

	/**
	 * @var string The data type of the value at the end of the path.
	 */
	public $stringType;

	/**
	 * Generate the sql given the function parameters
	 * Path must be a '.' delimited representation of the path
	 *
	 * @param SqlWalker $sqlWalker
	 * @return string
	 */
	public function getSql(SqlWalker $sqlWalker)
	{
		$column = $this->stringColumn->dispatch($sqlWalker);

		$path 	= $this->stringJsonPath->dispatch($sqlWalker);

		$type 	= $this->stripQuotes($this->stringType->dispatch($sqlWalker));

		$jsonPath = $this->generateJsonPath($path);

		return 'CAST(' 	. $column . $jsonPath . ' AS '.$type.')';

	}

	/**
	 * Convert a '.' delimited representation of a json path to it's
	 * Postgres equivalent.
	 *
	 * @param $path
	 * @return string
	 */
	public function generateJsonPath($path){

		// Break the supplied path into it's segments
		$segments = explode('.', $this->stripQuotes($path));

		$jsonPath = '';

		// Rebuild the path using the postgres syntax.
		$cnt = 0;
		$total = count($segments);
		foreach($segments as $segment){

			$cnt++;

			if($cnt === $total){
				$jsonPath .= '->>\''; // ->> means return as string
			}else{
				$jsonPath .= '->\''; // -> means return object
			}

			$jsonPath .= $segment . '\'';

		}

		return $jsonPath;

	}

	/**
	 * Extract the parameters of the function while parsing a dql query
	 *
	 * Expects FUNC(column, jsonpath, type)
	 *
	 * @param Parser $parser
	 */
	public function parse(Parser $parser)
	{
		$parser->match(Lexer::T_IDENTIFIER);
		$parser->match(Lexer::T_OPEN_PARENTHESIS);

		$this->stringColumn = $parser->StringPrimary();

		$parser->match(Lexer::T_COMMA);

		$this->stringJsonPath = $parser->StringPrimary();

		$parser->match(Lexer::T_COMMA);

		$this->stringType = $parser->StringPrimary();

		$parser->match(Lexer::T_CLOSE_PARENTHESIS);

	}

	/**
	 * Strip out the first and last quote of a parameter value
	 *
	 * @param $value
	 * @return bool|string
	 */
	public function stripQuotes($value){

		return substr($value, 1, strlen($value)-2);

	}

}