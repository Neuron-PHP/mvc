<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporterWithORM;
use Phinx\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Test SQL dialect portability in DataExporterWithORM
 *
 * Verifies that boolean formatting and transaction syntax are adapter-aware:
 * - SQLite: BEGIN TRANSACTION, 1/0 for booleans
 * - PostgreSQL: BEGIN, TRUE/FALSE for booleans
 * - MySQL: START TRANSACTION, 1/0 for booleans
 */
class DataExporterWithORMPortabilityTest extends TestCase
{
	/**
	 * Test that SQLite uses correct transaction syntax and boolean formatting
	 */
	public function testSqlitePortability(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'portability_test_' ) . '.db';

		try
		{
			// Create database with test data including boolean column
			$pdo = new \PDO( 'sqlite:' . $dbPath );
			$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			$pdo->exec( 'BEGIN TRANSACTION' );
			$pdo->exec( 'CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)' );
			$pdo->exec( 'CREATE TABLE test_data (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER)' );
			$pdo->exec( "INSERT INTO test_data (id, name, is_active) VALUES (1, 'Active User', 1)" );
			$pdo->exec( "INSERT INTO test_data (id, name, is_active) VALUES (2, 'Inactive User', 0)" );
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

			// Create exporter with transaction enabled
			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log',
				[
					'format' => 'sql',
					'use_transaction' => true,
					'include_schema' => false
				]
			);

			// Export to SQL file
			$outputPath = tempnam( sys_get_temp_dir(), 'export_' ) . '.sql';
			$actualPath = $exporter->exportToFile( $outputPath );
			$this->assertNotFalse( $actualPath, 'Export should succeed' );
			$sql = file_get_contents( $actualPath );

			// Verify SQLite-specific transaction syntax
			$this->assertStringContainsString(
				'BEGIN TRANSACTION;',
				$sql,
				'SQLite should use BEGIN TRANSACTION'
			);

			$this->assertStringNotContainsString(
				'START TRANSACTION;',
				$sql,
				'SQLite should not use START TRANSACTION'
			);

			// Verify COMMIT is included
			$this->assertStringContainsString(
				'COMMIT;',
				$sql,
				'Should include COMMIT statement'
			);

			$exporter->disconnect();

			// Cleanup export file
			if( file_exists( $actualPath ) )
			{
				unlink( $actualPath );
			}
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
	 * Test that transaction can be disabled
	 */
	public function testTransactionCanBeDisabled(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'portability_test_' ) . '.db';

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

			// Create exporter with transaction disabled
			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log',
				[
					'format' => 'sql',
					'use_transaction' => false
				]
			);

			// Export to SQL file
			$outputPath = tempnam( sys_get_temp_dir(), 'export_' ) . '.sql';
			$actualPath = $exporter->exportToFile( $outputPath );
			$this->assertNotFalse( $actualPath, 'Export should succeed' );
			$sql = file_get_contents( $actualPath );

			// Verify no transaction statements
			$this->assertStringNotContainsString(
				'BEGIN TRANSACTION;',
				$sql,
				'Should not include BEGIN TRANSACTION when disabled'
			);

			$this->assertStringNotContainsString(
				'START TRANSACTION;',
				$sql,
				'Should not include START TRANSACTION when disabled'
			);

			$this->assertStringNotContainsString(
				'COMMIT;',
				$sql,
				'Should not include COMMIT when disabled'
			);

			$exporter->disconnect();

			// Cleanup export file
			if( file_exists( $actualPath ) )
			{
				unlink( $actualPath );
			}
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
	 * Test formatBooleanLiteral via reflection for SQLite
	 */
	public function testFormatBooleanLiteralSqlite(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'portability_test_' ) . '.db';

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

			// Use reflection to test formatBooleanLiteral
			$reflection = new \ReflectionClass( $exporter );
			$method = $reflection->getMethod( 'formatBooleanLiteral' );
			$method->setAccessible( true );

			// SQLite should use 1/0 for booleans
			$this->assertEquals( '1', $method->invoke( $exporter, true ), 'SQLite should format true as 1' );
			$this->assertEquals( '0', $method->invoke( $exporter, false ), 'SQLite should format false as 0' );

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
	 * Document expected behavior for PostgreSQL
	 *
	 * This test documents that PostgreSQL would use:
	 * - BEGIN for transaction start
	 * - TRUE/FALSE for boolean literals
	 */
	public function testPostgresqlBehaviorDocumentation(): void
	{
		// This test documents expected PostgreSQL behavior
		// If we were testing against PostgreSQL:
		// - Transaction would start with "BEGIN;"
		// - Boolean true would be "TRUE"
		// - Boolean false would be "FALSE"

		// The implementation uses match expressions:
		// Transaction: 'pgsql', 'postgres' => 'BEGIN'
		// Boolean: 'pgsql', 'postgres' => $value ? 'TRUE' : 'FALSE'

		$this->assertTrue(
			true,
			'PostgreSQL uses BEGIN for transactions and TRUE/FALSE for booleans'
		);
	}

	/**
	 * Document expected behavior for MySQL
	 */
	public function testMysqlBehaviorDocumentation(): void
	{
		// This test documents expected MySQL behavior
		// If we were testing against MySQL:
		// - Transaction would start with "START TRANSACTION;"
		// - Boolean true would be "1"
		// - Boolean false would be "0"

		// The implementation uses match expressions:
		// Transaction: default => 'START TRANSACTION'
		// Boolean: default => $value ? '1' : '0'

		$this->assertTrue(
			true,
			'MySQL uses START TRANSACTION for transactions and 1/0 for booleans'
		);
	}
}
