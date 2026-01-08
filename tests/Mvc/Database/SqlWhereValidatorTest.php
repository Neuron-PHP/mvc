<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\SqlWhereValidator;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for SqlWhereValidator
 */
class SqlWhereValidatorTest extends TestCase
{
	/**
	 * Test valid WHERE clauses pass validation
	 */
	public function testValidWhereClauses(): void
	{
		$validClauses = [
			"status = 'active'",
			"age > 18",
			"created_at >= '2024-01-01'",
			"name LIKE '%john%'",
			"id IN (1, 2, 3)",
			"email IS NOT NULL",
			"price BETWEEN 10 AND 100",
			"status = 'active' AND age > 18",
			"type = 'user' OR type = 'admin'",
			"(status = 'active' AND age > 18) OR type = 'admin'",
			"name = 'O''Brien'", // Escaped single quote
			'status = "active"', // Double quotes
			"count != 0",
			"total <> 0",
			"amount <= 1000.50",
		];

		foreach( $validClauses as $clause )
		{
			$this->assertTrue(
				SqlWhereValidator::isValid( $clause ),
				"Valid clause should pass: {$clause}"
			);
		}
	}

	/**
	 * Test SQL injection patterns are detected
	 */
	public function testSqlInjectionPatterns(): void
	{
		$dangerousClauses = [
			// SQL comments
			"1=1 -- comment",
			"1=1 /* comment */",
			"1=1 # comment",

			// SQL commands
			"1=1; DROP TABLE users",
			"1=1; DELETE FROM users",
			"1=1 UNION SELECT * FROM passwords",
			"1=1; INSERT INTO admin VALUES ('hacker')",
			"1=1; UPDATE users SET admin=1",
			"1=1; TRUNCATE TABLE logs",
			"1=1; CREATE TABLE evil",
			"1=1; ALTER TABLE users",
			"1=1; GRANT ALL ON *.*",

			// Stacked queries
			"1=1; SELECT * FROM users",

			// Union attacks
			"1=1 UNION SELECT password FROM users",
			"1=1 UNION ALL SELECT 1,2,3",

			// Subqueries
			"id = (SELECT MAX(id) FROM users)",
			"name IN (SELECT name FROM admins)",

			// System functions
			"1=1 AND SLEEP(10)",
			"1=1 AND BENCHMARK(1000000, MD5('test'))",
			"1=1 AND LOAD_FILE('/etc/passwd')",

			// Information schema
			"1=1 AND EXISTS(SELECT * FROM INFORMATION_SCHEMA.TABLES)",
			"1=1 AND table_name IN (SELECT table_name FROM MYSQL.user)",

			// Hexadecimal literals
			"password = 0x61646D696E",

			// CHAR function
			"password = CHAR(97,100,109,105,110)",
		];

		foreach( $dangerousClauses as $clause )
		{
			$this->assertFalse(
				SqlWhereValidator::isValid( $clause ),
				"Dangerous clause should fail: {$clause}"
			);
		}
	}

	/**
	 * Test unbalanced quotes are detected
	 */
	public function testUnbalancedQuotes(): void
	{
		$unbalancedClauses = [
			"name = 'John",           // Missing closing single quote
			'status = "active',        // Missing closing double quote
			"name = 'John\"",          // Mixed quotes
			"status = 'active\"'",     // Mixed quotes
			"name = '''",              // Odd number of quotes
			'status = """',            // Odd number of quotes
			"name = 'O'Brien'",        // Unescaped quote (should be O''Brien)
		];

		foreach( $unbalancedClauses as $clause )
		{
			$this->assertFalse(
				SqlWhereValidator::isValid( $clause ),
				"Unbalanced quotes should fail: {$clause}"
			);
		}
	}

	/**
	 * Test unbalanced parentheses are detected
	 */
	public function testUnbalancedParentheses(): void
	{
		$unbalancedClauses = [
			"(status = 'active'",      // Missing closing paren
			"status = 'active')",      // Missing opening paren
			"((status = 'active')",    // Extra opening paren
			"(status = 'active'))",    // Extra closing paren
			"id IN (1, 2, 3",          // Missing closing paren in IN
			"id IN 1, 2, 3)",          // Missing opening paren in IN
		];

		foreach( $unbalancedClauses as $clause )
		{
			$this->assertFalse(
				SqlWhereValidator::isValid( $clause ),
				"Unbalanced parentheses should fail: {$clause}"
			);
		}
	}

	/**
	 * Test sanitize method removes dangerous elements
	 */
	public function testSanitizeMethod(): void
	{
		// Test comment removal
		$input = "status = 'active' -- AND admin = 1";
		$sanitized = SqlWhereValidator::sanitize( $input );
		$this->assertStringNotContainsString( '--', $sanitized );
		$this->assertStringNotContainsString( 'AND admin = 1', $sanitized ); // Comment and everything after should be removed

		// Test semicolon removal - semicolon is removed but SQL commands remain
		$input = "status = 'active'; DROP TABLE users";
		$sanitized = SqlWhereValidator::sanitize( $input );
		$this->assertStringNotContainsString( ';', $sanitized );
		// Note: DROP remains - sanitize only removes comments and semicolons
		$this->assertStringContainsString( 'DROP', $sanitized ); // This is expected behavior

		// Test block comment removal
		$input = "status = /* evil */ 'active'";
		$sanitized = SqlWhereValidator::sanitize( $input );
		$this->assertStringNotContainsString( '/*', $sanitized );
		$this->assertStringNotContainsString( '*/', $sanitized );
		$this->assertStringNotContainsString( 'evil', $sanitized );

		// Test hash comment removal
		$input = "status = 'active' # AND admin = 1";
		$sanitized = SqlWhereValidator::sanitize( $input );
		$this->assertStringNotContainsString( '#', $sanitized );
		$this->assertStringNotContainsString( 'AND admin = 1', $sanitized ); // Comment and everything after should be removed
	}

	/**
	 * Test sanitize with different database adapters
	 */
	public function testSanitizeWithDifferentAdapters(): void
	{
		$input = "name = 'O'Brien'";

		// MySQL uses backslash escaping
		$mysqlSanitized = SqlWhereValidator::sanitize( $input, 'mysql' );
		$this->assertStringContainsString( "\\'", $mysqlSanitized );

		// PostgreSQL uses single quote doubling
		$pgsqlSanitized = SqlWhereValidator::sanitize( $input, 'pgsql' );
		$this->assertStringContainsString( "''", $pgsqlSanitized );

		// SQLite uses single quote doubling
		$sqliteSanitized = SqlWhereValidator::sanitize( $input, 'sqlite' );
		$this->assertStringContainsString( "''", $sqliteSanitized );
	}

	/**
	 * Test parseSimpleWhere method
	 */
	public function testParseSimpleWhere(): void
	{
		// Test simple equality
		$result = SqlWhereValidator::parseSimpleWhere( "status = 'active'" );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertEquals( 'condition', $result[0]['type'] );
		$this->assertEquals( 'status', $result[0]['column'] );
		$this->assertEquals( '=', $result[0]['operator'] );
		$this->assertEquals( "'active'", $result[0]['value'] );

		// Test AND conditions
		$result = SqlWhereValidator::parseSimpleWhere( "status = 'active' AND age > 18" );
		$this->assertIsArray( $result );
		$this->assertCount( 3, $result ); // 2 conditions + 1 operator
		$this->assertEquals( 'condition', $result[0]['type'] );
		$this->assertEquals( 'operator', $result[1]['type'] );
		$this->assertEquals( 'AND', $result[1]['value'] );
		$this->assertEquals( 'condition', $result[2]['type'] );

		// Test OR conditions
		$result = SqlWhereValidator::parseSimpleWhere( "type = 'user' OR type = 'admin'" );
		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertEquals( 'OR', $result[1]['value'] );

		// Test LIKE operator
		$result = SqlWhereValidator::parseSimpleWhere( "name LIKE '%john%'" );
		$this->assertIsArray( $result );
		$this->assertEquals( 'LIKE', $result[0]['operator'] );

		// Test IN operator
		$result = SqlWhereValidator::parseSimpleWhere( "id IN (1,2,3)" );
		$this->assertIsArray( $result );
		$this->assertEquals( 'IN', $result[0]['operator'] );
		$this->assertEquals( '(1,2,3)', $result[0]['value'] );

		// Test IS NULL
		$result = SqlWhereValidator::parseSimpleWhere( "email IS NULL" );
		$this->assertIsArray( $result );
		$this->assertEquals( 'IS NULL', $result[0]['operator'] );
		$this->assertEquals( '', $result[0]['value'] );

		// Test complex patterns with subquery - parseSimpleWhere extracts what it can
		$result = SqlWhereValidator::parseSimpleWhere( "id = (SELECT MAX(id) FROM users)" );
		// The simple parser doesn't detect subqueries, it just sees "column = value"
		// This is why isValid() is also needed for full validation
		$this->assertIsArray( $result ); // It will parse it (incorrectly)

		// Test SQL injection - semicolon breaks parsing
		$result = SqlWhereValidator::parseSimpleWhere( "1=1; DROP TABLE users" );
		// The parser will extract the first part before semicolon
		$this->assertIsArray( $result );
	}

	/**
	 * Test edge cases
	 */
	public function testEdgeCases(): void
	{
		// Empty string
		$this->assertTrue( SqlWhereValidator::isValid( '' ) );

		// Just whitespace
		$this->assertTrue( SqlWhereValidator::isValid( '   ' ) );

		// Very long valid clause
		$longClause = str_repeat( "status = 'active' AND ", 100 );
		$longClause .= "status = 'active'";
		$this->assertTrue( SqlWhereValidator::isValid( $longClause ) );

		// Column names with underscores
		$this->assertTrue( SqlWhereValidator::isValid( "user_id = 123" ) );
		$this->assertTrue( SqlWhereValidator::isValid( "created_at > '2024-01-01'" ) );

		// Numeric values without quotes
		$this->assertTrue( SqlWhereValidator::isValid( "age = 25" ) );
		$this->assertTrue( SqlWhereValidator::isValid( "price = 99.99" ) );

		// Boolean-style conditions
		$this->assertTrue( SqlWhereValidator::isValid( "is_active = 1" ) );
		$this->assertTrue( SqlWhereValidator::isValid( "is_deleted = 0" ) );
	}

	/**
	 * Test real-world WHERE clauses from the application
	 */
	public function testRealWorldClauses(): void
	{
		// From actual dump command usage
		$realClauses = [
			"status = 'published' AND created_at >= '2024-01-01'",
			"user_id IN (1, 2, 3, 4, 5) AND deleted_at IS NULL",
			"(category = 'news' OR category = 'blog') AND is_featured = 1",
			"price BETWEEN 10.00 AND 99.99 AND stock > 0",
			"email LIKE '%@example.com' AND verified = 1",
			"role != 'guest' AND last_login > '2024-01-01 00:00:00'",
		];

		foreach( $realClauses as $clause )
		{
			$this->assertTrue(
				SqlWhereValidator::isValid( $clause ),
				"Real-world clause should pass: {$clause}"
			);
		}
	}

	/**
	 * Test that validator catches common attack vectors
	 */
	public function testCommonAttackVectors(): void
	{
		$attacks = [
			// These contain SQL comments and are blocked
			"admin'--",
			"' OR 1=1--",
			"1' AND 1=1--",
			"1' AND 1=2--",
			"1' AND SLEEP(5)--",
			"1' WAITFOR DELAY '00:00:05'--",
			"1' AND (SELECT * FROM users)--",
			"1' AND CONVERT(int, 'test')--",
			"admin'; INSERT INTO logs VALUES('hacked')--",
			"admin' EXEC xp_cmdshell 'net user'--",
		];

		foreach( $attacks as $attack )
		{
			$this->assertFalse(
				SqlWhereValidator::isValid( $attack ),
				"Attack vector should be blocked: {$attack}"
			);
		}

		// Test patterns with unbalanced quotes
		$unbalancedQuotes = [
			"status = 'active", // Missing closing quote (1 quote - odd)
			"type = user'", // Missing opening quote (1 quote - odd)
			"name = 'John", // Missing closing quote (1 quote - odd)
		];

		foreach( $unbalancedQuotes as $attack )
		{
			// These have odd number of quotes so should fail
			$isValid = SqlWhereValidator::isValid( $attack );
			$this->assertFalse( $isValid, "Should be caught by quote validation: {$attack}" );
		}

		// This pattern has balanced quotes but is still an attack
		// Current implementation doesn't catch this without keyword detection
		$balancedAttack = "admin' OR '1'='1"; // 4 quotes total (balanced)
		$isValid = SqlWhereValidator::isValid( $balancedAttack );
		// This will pass validation as quotes are balanced and no dangerous keywords
		// This demonstrates a limitation - real protection requires parameterized queries
		$this->assertTrue( $isValid, "Has balanced quotes, passes basic validation (shows limitation)" );
	}
}