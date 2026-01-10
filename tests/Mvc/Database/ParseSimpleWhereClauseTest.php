<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use Phinx\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Test parseSimpleWhereClause method handles empty quoted values correctly
 *
 * This test specifically verifies the fix for the bug where !empty($match[3])
 * incorrectly evaluated to false for empty quoted strings like status = ''.
 */
class ParseSimpleWhereClauseTest extends TestCase
{
	private $exporter;
	private $method;

	protected function setUp(): void
	{
		// Create a temporary SQLite database for the exporter
		$dbPath = tempnam( sys_get_temp_dir(), 'parse_test_' ) . '.db';

		// Create minimal database with phinx_log table
		$pdo = new \PDO( 'sqlite:' . $dbPath );
		$pdo->exec( 'CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)' );
		$pdo = null; // Close to flush

		// Create Phinx config
		$config = new Config( [
			'paths' => [
				'migrations' => __DIR__
			],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $dbPath
				]
			]
		] );

		// Create exporter
		$this->exporter = new DataExporter(
			$config,
			'testing',
			'phinx_log',
			[]
		);

		// Use reflection to access private parseSimpleWhereClause method
		$reflection = new \ReflectionClass( $this->exporter );
		$this->method = $reflection->getMethod( 'parseSimpleWhereClause' );
		$this->method->setAccessible( true );
	}

	protected function tearDown(): void
	{
		if( isset( $this->exporter ) )
		{
			$this->exporter->disconnect();
		}
	}

	/**
	 * Test that empty single-quoted values are parsed correctly
	 */
	public function testParsesEmptySingleQuotedValue(): void
	{
		$whereClause = "status = ''";
		$result = $this->method->invoke( $this->exporter, $whereClause );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertArrayHasKey( 'sql', $result, 'Should have sql key' );
		$this->assertArrayHasKey( 'bindings', $result, 'Should have bindings key' );

		// Should have one binding with empty string value
		$this->assertCount( 1, $result['bindings'], 'Should have 1 binding' );
		$this->assertSame( '', $result['bindings'][0], 'Binding should be empty string' );

		// SQL should have placeholder
		$this->assertStringContainsString( '?', $result['sql'], 'SQL should contain placeholder' );
	}

	/**
	 * Test that empty double-quoted values are parsed correctly
	 */
	public function testParsesEmptyDoubleQuotedValue(): void
	{
		$whereClause = 'description = ""';
		$result = $this->method->invoke( $this->exporter, $whereClause );

		$this->assertIsArray( $result, 'Should return array' );

		// Should have one binding with empty string value
		$this->assertCount( 1, $result['bindings'], 'Should have 1 binding' );
		$this->assertSame( '', $result['bindings'][0], 'Binding should be empty string' );
	}

	/**
	 * Test that compound WHERE with empty value works
	 */
	public function testParsesCompoundWhereWithEmptyValue(): void
	{
		$whereClause = "status = '' AND code = 'A'";
		$result = $this->method->invoke( $this->exporter, $whereClause );

		$this->assertIsArray( $result, 'Should return array' );

		// Should have two bindings: empty string and 'A'
		$this->assertCount( 2, $result['bindings'], 'Should have 2 bindings' );
		$this->assertSame( '', $result['bindings'][0], 'First binding should be empty string' );
		$this->assertSame( 'A', $result['bindings'][1], 'Second binding should be A' );

		// SQL should contain AND
		$this->assertStringContainsString( 'AND', $result['sql'], 'SQL should contain AND' );
	}

	/**
	 * Test that non-empty values still work after fix
	 */
	public function testParsesNonEmptyValues(): void
	{
		$whereClause = "status = 'active'";
		$result = $this->method->invoke( $this->exporter, $whereClause );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result['bindings'], 'Should have 1 binding' );
		$this->assertSame( 'active', $result['bindings'][0], 'Binding should be active' );
	}

	/**
	 * Test unquoted values (which cannot be empty)
	 */
	public function testParsesUnquotedValues(): void
	{
		$whereClause = "count = 123";
		$result = $this->method->invoke( $this->exporter, $whereClause );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result['bindings'], 'Should have 1 binding' );
		$this->assertSame( '123', $result['bindings'][0], 'Binding should be 123' );
	}

	/**
	 * Test SQL-escaped quotes in empty values
	 * e.g., name = '' (truly empty) vs name = 'O''Brien' (non-empty with escaped quote)
	 */
	public function testDistinguishesEmptyFromEscapedQuotes(): void
	{
		// Empty value
		$whereClause1 = "name = ''";
		$result1 = $this->method->invoke( $this->exporter, $whereClause1 );
		$this->assertSame( '', $result1['bindings'][0], 'Empty quoted value should be empty string' );

		// Non-empty value with escaped quote
		$whereClause2 = "name = 'O''Brien'";
		$result2 = $this->method->invoke( $this->exporter, $whereClause2 );
		$this->assertSame( "O'Brien", $result2['bindings'][0], 'Escaped quote should be unescaped' );
	}

	/**
	 * Test mixed empty and non-empty values in same WHERE clause
	 */
	public function testMixedEmptyAndNonEmptyValues(): void
	{
		$whereClause = "status = '' OR status = 'active' OR description = ''";
		$result = $this->method->invoke( $this->exporter, $whereClause );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 3, $result['bindings'], 'Should have 3 bindings' );
		$this->assertSame( '', $result['bindings'][0], 'First binding should be empty' );
		$this->assertSame( 'active', $result['bindings'][1], 'Second binding should be active' );
		$this->assertSame( '', $result['bindings'][2], 'Third binding should be empty' );
	}
}
