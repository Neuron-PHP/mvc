<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Test null check handling in DataExporter's getTableCreateStatement method
 * Verifies fix for crash when fetchRow returns null/false
 */
class DataExporterNullCheckTest extends TestCase
{
	private $tempDir;
	private static $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp directory for test files
		$this->tempDir = sys_get_temp_dir() . '/null_check_test_' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		// Capture original AdapterFactory
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		self::$originalFactory = $instanceProperty->getValue();
	}

	protected function tearDown(): void
	{
		// Clean up temp directory
		if( is_dir( $this->tempDir ) )
		{
			$this->recursiveRemoveDir( $this->tempDir );
		}

		// Restore original AdapterFactory
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, self::$originalFactory );

		parent::tearDown();
	}

	/**
	 * Test that export handles false/null result from fetchRow gracefully (MySQL)
	 */
	public function testMysqlHandlesFalseResultFromFetchRow(): void
	{
		$mockAdapter = $this->createMockAdapter( 'mysql' );
		$mockConfig = $this->createMockConfig();

		// Simulate table list query
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'missing_table']
				];
			}
			// Return some data for the table
			if( strpos( $sql, 'SELECT * FROM' ) !== false )
			{
				return [
					['id' => 1, 'data' => 'test']
				];
			}
			return [];
		} );

		// Simulate fetchRow returning false (table dropped or query failed)
		$mockAdapter->method( 'fetchRow' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'SHOW CREATE TABLE' ) !== false )
			{
				// Simulate table not found or query failure
				return false;
			}
			// Default for count queries
			return ['count' => 1];
		} );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'sql',
				'include_schema' => true, // This triggers getTableCreateStatement
				'drop_tables' => false
			]
		);

		// Export should not crash despite null result
		$outputPath = $this->tempDir . '/export_mysql_null.sql';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertNotFalse( $result );
		$this->assertFileExists( $outputPath );

		$content = file_get_contents( $outputPath );
		// Should contain a comment about the missing CREATE TABLE statement
		$this->assertStringContainsString( 'CREATE TABLE statement not available', $content );
		$this->assertStringContainsString( 'missing_table', $content );
		// Should still contain the data
		$this->assertStringContainsString( 'INSERT INTO', $content );
	}

	/**
	 * Test that export handles null result from fetchRow gracefully (SQLite)
	 */
	public function testSqliteHandlesNullResultFromFetchRow(): void
	{
		$mockAdapter = $this->createMockAdapter( 'sqlite' );
		$mockConfig = $this->createMockConfig();

		// Simulate table list query
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'sqlite_master' ) !== false && strpos( $sql, 'SELECT name' ) !== false )
			{
				return [
					['name' => 'phantom_table']
				];
			}
			// Return some data for the table
			if( strpos( $sql, 'SELECT * FROM' ) !== false )
			{
				return [
					['id' => 1, 'value' => 'test']
				];
			}
			return [];
		} );

		// Simulate fetchRow returning false (table doesn't exist in sqlite_master)
		$mockAdapter->method( 'fetchRow' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'SELECT sql FROM sqlite_master' ) !== false )
			{
				// Simulate table not found in sqlite_master
				return false;
			}
			// Default for count queries
			return ['count' => 1];
		} );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'sql',
				'include_schema' => true,
				'drop_tables' => false
			]
		);

		// Export should not crash despite false result
		$outputPath = $this->tempDir . '/export_sqlite_false.sql';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertNotFalse( $result );
		$this->assertFileExists( $outputPath );

		$content = file_get_contents( $outputPath );
		// Should contain a comment about the missing CREATE TABLE statement
		$this->assertStringContainsString( 'CREATE TABLE statement not available', $content );
		$this->assertStringContainsString( 'phantom_table', $content );
		// Should still contain the data
		$this->assertStringContainsString( 'INSERT INTO', $content );
	}

	/**
	 * Test that export handles missing array key gracefully
	 */
	public function testHandlesMissingArrayKey(): void
	{
		$mockAdapter = $this->createMockAdapter( 'mysql' );
		$mockConfig = $this->createMockConfig();

		// Simulate table list query
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'malformed_result']
				];
			}
			// Return some data for the table
			if( strpos( $sql, 'SELECT * FROM' ) !== false )
			{
				return [
					['id' => 1, 'data' => 'test']
				];
			}
			return [];
		} );

		// Simulate fetchRow returning array but with wrong keys
		$mockAdapter->method( 'fetchRow' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'SHOW CREATE TABLE' ) !== false )
			{
				// Return array but without expected 'Create Table' key
				return ['wrong_key' => 'CREATE TABLE test (id INT)'];
			}
			// Default for count queries
			return ['count' => 1];
		} );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'sql',
				'include_schema' => true,
				'drop_tables' => false
			]
		);

		// Export should not crash despite missing array key
		$outputPath = $this->tempDir . '/export_wrong_key.sql';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertNotFalse( $result );
		$this->assertFileExists( $outputPath );

		$content = file_get_contents( $outputPath );
		// Should contain a comment about the missing CREATE TABLE statement
		$this->assertStringContainsString( 'CREATE TABLE statement not available', $content );
		// Should still contain the data
		$this->assertStringContainsString( 'INSERT INTO', $content );
	}

	/**
	 * Test normal operation still works correctly
	 */
	public function testNormalOperationStillWorks(): void
	{
		$mockAdapter = $this->createMockAdapter( 'mysql' );
		$mockConfig = $this->createMockConfig();

		// Simulate table list query
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'normal_table']
				];
			}
			// Return some data for the table
			if( strpos( $sql, 'SELECT * FROM' ) !== false )
			{
				return [
					['id' => 1, 'data' => 'test']
				];
			}
			return [];
		} );

		// Simulate normal fetchRow response
		$mockAdapter->method( 'fetchRow' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'SHOW CREATE TABLE' ) !== false )
			{
				// Return proper result
				return ['Create Table' => 'CREATE TABLE `normal_table` (id INT PRIMARY KEY)'];
			}
			// Default for count queries
			return ['count' => 1];
		} );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'sql',
				'include_schema' => true,
				'drop_tables' => false
			]
		);

		// Export should work normally
		$outputPath = $this->tempDir . '/export_normal.sql';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertNotFalse( $result );
		$this->assertFileExists( $outputPath );

		$content = file_get_contents( $outputPath );
		// Should contain the actual CREATE TABLE statement
		$this->assertStringContainsString( 'CREATE TABLE `normal_table`', $content );
		$this->assertStringContainsString( 'id INT PRIMARY KEY', $content );
		// Should not contain error message
		$this->assertStringNotContainsString( 'statement not available', $content );
		// Should contain the data
		$this->assertStringContainsString( 'INSERT INTO', $content );
	}

	// Helper methods

	private function createMockAdapter( string $adapterType = 'mysql' )
	{
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( $adapterType );
		$mockAdapter->method( 'disconnect' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );

		return $mockAdapter;
	}

	private function createMockConfig(): Config
	{
		return new Config( [
			'paths' => [
				'migrations' => '/test/migrations'
			],
			'environments' => [
				'testing' => [
					'adapter' => 'mysql',
					'host' => 'localhost',
					'name' => 'test_db',
					'user' => 'root',
					'pass' => '',
					'port' => 3306
				]
			]
		] );
	}

	private function mockAdapterFactory( $mockAdapter ): void
	{
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );

		$mockFactory = $this->createMock( AdapterFactory::class );
		$mockFactory->method( 'getAdapter' )->willReturn( $mockAdapter );

		$instanceProperty->setValue( null, $mockFactory );
	}

	private function recursiveRemoveDir( $dir ): void
	{
		if( !is_dir( $dir ) ) return;

		$objects = scandir( $dir );
		foreach( $objects as $object )
		{
			if( $object != "." && $object != ".." )
			{
				if( is_dir( $dir . "/" . $object ) )
				{
					$this->recursiveRemoveDir( $dir . "/" . $object );
				}
				else
				{
					unlink( $dir . "/" . $object );
				}
			}
		}
		rmdir( $dir );
	}
}