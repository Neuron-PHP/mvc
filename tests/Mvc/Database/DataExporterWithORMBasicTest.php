<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporterWithORM;
use Phinx\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Basic test that DataExporterWithORM connects properly
 *
 * This verifies the fix where connect() was missing before getConnection(),
 * which would cause the class to fail on instantiation.
 */
class DataExporterWithORMBasicTest extends TestCase
{
	/**
	 * Test that DataExporterWithORM can be instantiated without error
	 *
	 * This is the core test - before the fix, getConnection() would fail
	 * because connect() wasn't called first.
	 */
	public function testCanInstantiateWithoutError(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'orm_test_' );

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

			// This should not throw an exception
			// Before fix: Would fail because getConnection() called before connect()
			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log'
			);

			$this->assertInstanceOf( DataExporterWithORM::class, $exporter );

			// Verify we have a valid PDO connection
			$reflection = new \ReflectionClass( $exporter );
			$pdoProperty = $reflection->getProperty( '_pdo' );
			$pdoProperty->setAccessible( true );
			$pdo = $pdoProperty->getValue( $exporter );

			$this->assertInstanceOf( \PDO::class, $pdo, 'Should have valid PDO connection' );

			// Verify disconnect works
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
	 * Test that adapter is connected after instantiation
	 */
	public function testAdapterIsConnected(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'orm_test_' );

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

			// Access the private adapter to verify it's connected
			$reflection = new \ReflectionClass( $exporter );
			$adapterProperty = $reflection->getProperty( '_adapter' );
			$adapterProperty->setAccessible( true );
			$adapter = $adapterProperty->getValue( $exporter );

			// The adapter should have a valid connection
			$this->assertNotNull( $adapter->getConnection(), 'Adapter should have connection' );

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
	 * Test that getConnection works after connect is called
	 */
	public function testGetConnectionWorksAfterConnect(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'orm_test_' );

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

			// Create exporter - this tests that connect() is called before getConnection()
			$exporter = new DataExporterWithORM(
				$config,
				'testing',
				'phinx_log'
			);

			// Access PDO to verify we can execute queries
			$reflection = new \ReflectionClass( $exporter );
			$pdoProperty = $reflection->getProperty( '_pdo' );
			$pdoProperty->setAccessible( true );
			$pdo = $pdoProperty->getValue( $exporter );

			// This should work if connection was established
			$stmt = $pdo->query( "SELECT 1 as test" );
			$result = $stmt->fetch( \PDO::FETCH_ASSOC );

			$this->assertEquals( 1, $result['test'], 'Should be able to execute queries' );

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
