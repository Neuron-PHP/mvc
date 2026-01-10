<?php

namespace Neuron\Mvc\Database;

/**
 * SQL WHERE clause validator to prevent SQL injection attacks
 *
 * SECURITY NOTE: This class does NOT provide a sanitize() method by design.
 * Attempting to sanitize SQL input is dangerous and error-prone. Instead:
 *
 * 1. Use isValid() to REJECT dangerous input (defense in depth)
 * 2. Use PDO prepared statements with parameter binding (primary defense)
 * 3. Never concatenate user input into SQL queries
 *
 * Why no sanitize() method:
 * - addslashes() is unsafe (doesn't handle multi-byte encodings like GBK)
 * - String escaping cannot protect against all SQL injection vectors
 * - Future maintainers might assume sanitize() makes input safe (it doesn't)
 * - The correct solution is parameterized queries, not string manipulation
 */
class SqlWhereValidator
{
	/**
	 * Dangerous SQL patterns that could indicate SQL injection
	 */
	private const DANGEROUS_PATTERNS = [
		// SQL comments
		'/--/',
		'/\/\*.*\*\//',
		'/#/',

		// SQL commands
		'/\b(DROP|CREATE|ALTER|TRUNCATE|DELETE|INSERT|UPDATE|REPLACE|GRANT|REVOKE)\b/i',

		// Stacked queries
		'/;\s*\w+/',

		// Union attacks
		'/\bUNION\b/i',

		// Subqueries (can be dangerous in WHERE clauses)
		'/\bSELECT\b.*\bFROM\b/i',

		// System functions that could be exploited
		'/\b(SLEEP|BENCHMARK|LOAD_FILE|OUTFILE|DUMPFILE)\b/i',

		// Information schema access
		'/\b(INFORMATION_SCHEMA|MYSQL|PERFORMANCE_SCHEMA)\b/i',

		// Hexadecimal literals (often used in attacks)
		'/0x[0-9a-fA-F]+/',

		// CHAR function (used to obfuscate attacks)
		'/\bCHAR\s*\(/i',
	];

	/**
	 * Validate a WHERE clause for SQL injection attempts
	 *
	 * @param string $whereClause The WHERE clause to validate
	 * @return bool True if safe, false if potentially dangerous
	 */
	public static function isValid( string $whereClause ): bool
	{
		// Check for dangerous patterns
		foreach( self::DANGEROUS_PATTERNS as $pattern )
		{
			if( preg_match( $pattern, $whereClause ) )
			{
				return false;
			}
		}

		// Check for balanced quotes (basic check)
		// Account for both backslash-escaped quotes and SQL-style doubled quotes
		$singleQuotes = substr_count( $whereClause, "'" )
			- substr_count( $whereClause, "\\'" )
			- (2 * substr_count( $whereClause, "''" )); // SQL-style escaped single quotes
		$doubleQuotes = substr_count( $whereClause, '"' )
			- substr_count( $whereClause, '\\"' )
			- (2 * substr_count( $whereClause, '""' )); // SQL-style escaped double quotes

		if( $singleQuotes % 2 !== 0 || $doubleQuotes % 2 !== 0 )
		{
			return false;
		}

		// Check for balanced parentheses
		$openParens = substr_count( $whereClause, '(' );
		$closeParens = substr_count( $whereClause, ')' );

		if( $openParens !== $closeParens )
		{
			return false;
		}

		return true;
	}

	/**
	 * Parse a simple WHERE clause into safe components
	 * Only allows basic conditions like: column = 'value', column > 123, etc.
	 *
	 * @param string $whereClause
	 * @return array|false Array of parsed conditions or false if unsafe
	 */
	public static function parseSimpleWhere( string $whereClause ): array|false
	{
		// Validate input before parsing - reject any potentially unsafe clauses
		if( !self::isValid( $whereClause ) )
		{
			return false;
		}

		// Pattern for simple conditions: column operator value
		// Allows: =, !=, <>, <, >, <=, >=, LIKE, NOT LIKE, IN, NOT IN, IS NULL, IS NOT NULL
		$pattern = '/^(\w+)\s*(=|!=|<>|<|>|<=|>=|LIKE|NOT\s+LIKE|IN|NOT\s+IN|IS\s+NULL|IS\s+NOT\s+NULL)\s*(.*)$/i';

		$conditions = [];
		$parts = preg_split( '/\s+(AND|OR)\s+/i', $whereClause, -1, PREG_SPLIT_DELIM_CAPTURE );

		for( $i = 0; $i < count( $parts ); $i++ )
		{
			$part = trim( $parts[$i] );

			// Skip AND/OR operators
			if( in_array( strtoupper( $part ), ['AND', 'OR'] ) )
			{
				$conditions[] = ['type' => 'operator', 'value' => strtoupper( $part )];
				continue;
			}

			// Parse the condition
			if( !preg_match( $pattern, $part, $matches ) )
			{
				return false; // Not a simple condition
			}

			$column = $matches[1];
			$operator = strtoupper( trim( $matches[2] ) );
			$value = isset( $matches[3] ) ? trim( $matches[3] ) : '';

			// Validate column name (alphanumeric and underscore only)
			if( !preg_match( '/^\w+$/', $column ) )
			{
				return false;
			}

			// For NULL checks, value should be empty
			if( in_array( $operator, ['IS NULL', 'IS NOT NULL'] ) && !empty( $value ) )
			{
				return false;
			}

			// For IN/NOT IN, value should be in parentheses
			if( in_array( $operator, ['IN', 'NOT IN'] ) )
			{
				if( !preg_match( '/^\(.*\)$/', $value ) )
				{
					return false;
				}
			}

			$conditions[] = [
				'type' => 'condition',
				'column' => $column,
				'operator' => $operator,
				'value' => $value
			];
		}

		return $conditions;
	}
}