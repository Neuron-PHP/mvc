<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporterWithORM;
use Phinx\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Test that DataExporterWithORM correctly handles SQL-escaped quotes in WHERE clauses
 *
 * This test verifies the fix for the bug where the WHERE parser used the pattern
 * [^\'"]*  which truncates values at the first quote character. For example,
 * name = 'O''Brien' would only match 'O' instead of 'O''Brien'.
 *
 * The fix uses the pattern (?:\'\'|[^\'])* to match either doubled quotes or
 * non-quote characters, and then unescapes with str_replace("''", "'", ...).
 */
class DataExporterWithORMEscapedQuotesTest extends TestCase
{
	private array $tempDbPaths = [];

	/**
	 * Create a unique temporary database path without using tempnam()
	 *
	 * @return string Path to temporary .db file
	 */
	private function createTempDbPath(): string
	{
		$path = sys_get_temp_dir() . '/' . uniqid( 'orm_escaped_test_', true ) . '.db';
		$this->tempDbPaths[] = $path;
		return $path;
	}

	protected function tearDown(): void
	{
		// Clean up any tracked temp database files
		foreach( $this->tempDbPaths as $path )
		{
			if( file_exists( $path ) )
			{
				unlink( $path );
			}
		}
		$this->tempDbPaths = [];

		parent::tearDown();
	}

	/**
	 * Test that parseWhereClause correctly handles SQL-escaped single quotes
	 */
	public function testParseSqlEscapedSingleQuotes(): void
	{
		// Create a temporary SQLite database
		$dbPath = $this->createTempDbPath();

		try
		{
			// Create database with test data
			$pdo = new \PDO( 'sqlite:' . $dbPath );
			$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			$pdo->exec( 'BEGIN TRANSACTION' );
			$pdo->exec( 'CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)' );
			$pdo->exec( 'CREATE TABLE names (id INTEGER PRIMARY KEY, name TEXT)' );
			$pdo->exec( "INSERT INTO names (id, name) VALUES (1, 'O''Brien')" );
			$pdo->exec( "INSERT INTO names (id, name) VALUES (2, 'Smith')" );
			$pdo->exec( "INSERT INTO names (id, name) VALUES (3, 'O''Connor')" );
			$pdo->exec( 'COMMIT' );
			unset( $pdo );

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

			// Create exporter with WHERE clause containing SQL-escaped quotes
			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log',
				[
					'format' => 'json',
					'where' => [
						'names' => "name = 'O''Brien'"
					]
				]
			);

			// Use reflection to test the private parseWhereClause method
			$reflection = new \ReflectionClass( $exporter );
			$method = $reflection->getMethod( 'parseWhereClause' );
			$method->setAccessible( true );

			// Parse the WHERE clause
			$result = $method->invoke( $exporter, "name = 'O''Brien'" );

			// Should have properly parsed and unescaped the value
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'sql', $result );
			$this->assertArrayHasKey( 'bindings', $result );
			$this->assertCount( 1, $result['bindings'], 'Should have 1 binding' );
			$this->assertEquals( "O'Brien", $result['bindings'][0], 'Should unescape doubled quotes to single quote' );

			$exporter->disconnect();
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test that parseWhereClause correctly handles SQL-escaped double quotes
	 */
	public function testParseSqlEscapedDoubleQuotes(): void
	{
		// Create a temporary SQLite database
		$dbPath = $this->createTempDbPath();

		try
		{
			// Create minimal database
			$pdo = new \PDO( 'sqlite:' . $dbPath );
			$pdo->exec( 'CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)' );
			unset( $pdo );

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

			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log'
			);

			// Use reflection to test parseWhereClause
			$reflection = new \ReflectionClass( $exporter );
			$method = $reflection->getMethod( 'parseWhereClause' );
			$method->setAccessible( true );

			// Parse WHERE clause with escaped double quotes
			$result = $method->invoke( $exporter, 'description = "He said ""hi"""' );

			$this->assertCount( 1, $result['bindings'], 'Should have 1 binding' );
			$this->assertEquals( 'He said "hi"', $result['bindings'][0], 'Should unescape doubled double quotes' );

			$exporter->disconnect();
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test multiple escaped quotes in one value
	 */
	public function testMultipleEscapedQuotesInValue(): void
	{
		// Create a temporary SQLite database
		$dbPath = $this->createTempDbPath();

		try
		{
			// Create minimal database
			$pdo = new \PDO( 'sqlite:' . $dbPath );
			$pdo->exec( 'CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)' );
			unset( $pdo );

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

			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log'
			);

			// Use reflection to test parseWhereClause
			$reflection = new \ReflectionClass( $exporter );
			$method = $reflection->getMethod( 'parseWhereClause' );
			$method->setAccessible( true );

			// Parse WHERE clause with multiple escaped quotes
			$result = $method->invoke( $exporter, "name = 'It''s John''s'" );

			$this->assertCount( 1, $result['bindings'], 'Should have 1 binding' );
			$this->assertEquals( "It's John's", $result['bindings'][0], 'Should unescape all doubled quotes' );

			$exporter->disconnect();
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test compound WHERE with escaped quotes
	 */
	public function testCompoundWhereWithEscapedQuotes(): void
	{
		// Create a temporary SQLite database
		$dbPath = $this->createTempDbPath();

		try
		{
			// Create minimal database
			$pdo = new \PDO( 'sqlite:' . $dbPath );
			$pdo->exec( 'CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)' );
			unset( $pdo );

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

			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log'
			);

			// Use reflection to test parseWhereClause
			$reflection = new \ReflectionClass( $exporter );
			$method = $reflection->getMethod( 'parseWhereClause' );
			$method->setAccessible( true );

			// Parse compound WHERE with multiple escaped values
			$result = $method->invoke( $exporter, "name = 'O''Brien' OR name = 'O''Connor'" );

			$this->assertCount( 2, $result['bindings'], 'Should have 2 bindings' );
			$this->assertEquals( "O'Brien", $result['bindings'][0], 'First binding should be unescaped' );
			$this->assertEquals( "O'Connor", $result['bindings'][1], 'Second binding should be unescaped' );

			// Verify SQL structure
			$this->assertStringContainsString( 'OR', $result['sql'], 'Should contain OR operator' );

			$exporter->disconnect();
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test that regular values without escaped quotes still work
	 */
	public function testRegularValuesStillWork(): void
	{
		// Create a temporary SQLite database
		$dbPath = $this->createTempDbPath();

		try
		{
			// Create minimal database
			$pdo = new \PDO( 'sqlite:' . $dbPath );
			$pdo->exec( 'CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)' );
			unset( $pdo );

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

			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log'
			);

			// Use reflection to test parseWhereClause
			$reflection = new \ReflectionClass( $exporter );
			$method = $reflection->getMethod( 'parseWhereClause' );
			$method->setAccessible( true );

			// Test various regular values
			$testCases = [
				["status = 'active'", 'active'],
				["count = 100", '100'],
				["name = 'Smith'", 'Smith'],
				['description = "test"', 'test'],
			];

			foreach( $testCases as [$whereClause, $expectedValue] )
			{
				$result = $method->invoke( $exporter, $whereClause );
				$this->assertCount( 1, $result['bindings'], "Should have 1 binding for: {$whereClause}" );
				$this->assertEquals( $expectedValue, $result['bindings'][0], "Value should match for: {$whereClause}" );
			}

			$exporter->disconnect();
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test empty quoted values work correctly
	 */
	public function testEmptyQuotedValues(): void
	{
		// Create a temporary SQLite database
		$dbPath = $this->createTempDbPath();

		try
		{
			// Create minimal database
			$pdo = new \PDO( 'sqlite:' . $dbPath );
			$pdo->exec( 'CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)' );
			unset( $pdo );

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

			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log'
			);

			// Use reflection to test parseWhereClause
			$reflection = new \ReflectionClass( $exporter );
			$method = $reflection->getMethod( 'parseWhereClause' );
			$method->setAccessible( true );

			// Test empty single-quoted value
			$result = $method->invoke( $exporter, "status = ''" );
			$this->assertCount( 1, $result['bindings'], 'Should have 1 binding for empty single-quoted' );
			$this->assertSame( '', $result['bindings'][0], 'Should be empty string' );

			// Test empty double-quoted value
			$result = $method->invoke( $exporter, 'status = ""' );
			$this->assertCount( 1, $result['bindings'], 'Should have 1 binding for empty double-quoted' );
			$this->assertSame( '', $result['bindings'][0], 'Should be empty string' );

			$exporter->disconnect();
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}
}
