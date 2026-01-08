<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use Neuron\Mvc\Database\SqlWhereValidator;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Comprehensive tests for DataExporter to improve code coverage
 */
class DataExporterComprehensiveTest extends TestCase
{
	private $tempDir;
	private $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp directory for test files
		$this->tempDir = sys_get_temp_dir() . '/dataexporter_test_' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		// Ensure clean state by resetting AdapterFactory at start of each test
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );
	}

	protected function tearDown(): void
	{
		// Reset AdapterFactory to null to ensure clean state
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Test export to SQL format
	 */
	public function testExportToSqlFormat(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock table data
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'users']
				];
			}
			// Return data for the users table
			if( strpos( $sql, 'SELECT * FROM `users`' ) !== false )
			{
				return [
					['id' => 1, 'name' => 'John', 'email' => 'john@example.com'],
					['id' => 2, 'name' => 'Jane', 'email' => 'jane@example.com']
				];
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 2] );
		// Remove this line - hasForeignKeys doesn't exist in AdapterInterface

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'sql', 'tables' => ['users']]
		);

		$outputPath = $this->tempDir . '/export.sql';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertFileExists( $outputPath );
		$content = file_get_contents( $outputPath );
		$this->assertStringContainsString( 'INSERT INTO', $content );
		$this->assertStringContainsString( 'users', $content );
		$this->assertStringContainsString( 'John', $content );
		$this->assertStringContainsString( 'john@example.com', $content );
	}

	/**
	 * Test export to JSON format
	 */
	public function testExportToJsonFormat(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock table data
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'users']
				];
			}
			// Return data for the users table
			if( strpos( $sql, 'SELECT * FROM `users`' ) !== false )
			{
				return [
					['id' => 1, 'name' => 'Test User', 'active' => true],
					['id' => 2, 'name' => 'Another User', 'active' => false]
				];
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 2] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'json', 'tables' => ['users']]
		);

		$outputPath = $this->tempDir . '/export.json';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertFileExists( $outputPath );
		$content = file_get_contents( $outputPath );
		$data = json_decode( $content, true );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'users', $data['data'] );
		$this->assertCount( 2, $data['data']['users']['rows'] );
		$this->assertEquals( 'Test User', $data['data']['users']['rows'][0]['name'] );
	}

	/**
	 * Test export to YAML format
	 */
	public function testExportToYamlFormat(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock table data
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'posts']
				];
			}
			// Return data for the posts table
			if( strpos( $sql, 'SELECT * FROM `posts`' ) !== false )
			{
				return [
					['id' => 1, 'title' => 'First Post', 'published' => 1],
					['id' => 2, 'title' => 'Second Post', 'published' => 0]
				];
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 2] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'yaml', 'tables' => ['posts']]
		);

		$outputPath = $this->tempDir . '/export.yaml';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertFileExists( $outputPath );
		$content = file_get_contents( $outputPath );

		$this->assertStringContainsString( 'data:', $content );
		$this->assertStringContainsString( 'posts:', $content );
		$this->assertStringContainsString( 'First Post', $content );
		$this->assertStringContainsString( 'Second Post', $content );
	}

	/**
	 * Test export to CSV format (directory)
	 */
	public function testExportToCsvFormat(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock table data
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'products']
				];
			}
			// Return data for the products table
			if( strpos( $sql, 'SELECT * FROM `products`' ) !== false )
			{
				return [
					['id' => 1, 'product' => 'Widget', 'price' => '19.99'],
					['id' => 2, 'product' => 'Gadget', 'price' => '29.99']
				];
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 2] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'csv', 'tables' => ['products']]
		);

		$outputPath = $this->tempDir . '/csv_export';
		$result = $exporter->exportCsvToDirectory( $outputPath );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result ); // products.csv and export_metadata.json
		$this->assertFileExists( $outputPath . '/products.csv' );
		$this->assertFileExists( $outputPath . '/export_metadata.json' );

		$csvContent = file_get_contents( $outputPath . '/products.csv' );
		$this->assertStringContainsString( 'id,product,price', $csvContent );
		$this->assertStringContainsString( 'Widget', $csvContent );
		$this->assertStringContainsString( '19.99', $csvContent );
	}

	/**
	 * Test WHERE clause filtering
	 */
	public function testWhereClauseFiltering(): void
	{
		$mockAdapter = $this->createPartialMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock fetchAll for getTables query
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'users']
				];
			}
			return [];
		} );

		// Create a PDO mock for testing WHERE clauses
		$mockPdo = $this->createMock( \PDO::class );

		// We need different statement mocks for different queries
		$tableStmt = $this->createMock( \PDOStatement::class );
		$dataStmt = $this->createMock( \PDOStatement::class );
		$countStmt = $this->createMock( \PDOStatement::class );

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );

		// Setup PDO to return different statements based on the SQL
		$mockPdo->method( 'prepare' )->willReturnCallback( function( $sql ) use ( $tableStmt, $dataStmt, $countStmt ) {
			// getTables query
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return $tableStmt;
			}
			// Count query
			if( strpos( $sql, 'SELECT COUNT(*)' ) !== false )
			{
				return $countStmt;
			}
			// Data query
			return $dataStmt;
		} );

		// Setup table statement to return table names
		$tableStmt->method( 'execute' )->willReturn( true );
		$tableStmt->method( 'fetchAll' )->willReturn( [
			['TABLE_NAME' => 'users']
		] );

		// Setup data statement to return filtered data
		$dataStmt->method( 'execute' )->willReturn( true );
		$dataStmt->method( 'fetchAll' )->willReturn( [
			['id' => 1, 'status' => 'active'],
			['id' => 3, 'status' => 'active']
		] );

		// Setup count statement
		$countStmt->method( 'execute' )->willReturn( true );
		$countStmt->method( 'fetch' )->willReturn( ['count' => 2] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'tables' => ['users'],
				'where' => ['users' => "status = 'active'"]
			]
		);

		$outputPath = $this->tempDir . '/filtered.json';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertFileExists( $outputPath );
		$content = file_get_contents( $outputPath );
		$data = json_decode( $content, true );

		// Debug output to see actual structure
		if( !isset($data['data']['users']) ) {
			echo "\nActual JSON data keys: " . print_r(array_keys($data['data'] ?? []), true);
			echo "\nFull JSON structure: " . print_r($data, true);
		}

		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'users', $data['data'] );
		$this->assertCount( 2, $data['data']['users']['rows'] );
		$this->assertEquals( 'active', $data['data']['users']['rows'][0]['status'] );
	}

	/**
	 * Test LIMIT option
	 */
	public function testLimitOption(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock limited data
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'items']
				];
			}
			// Return data for the items table
			if( strpos( $sql, 'SELECT * FROM `items`' ) !== false )
			{
				return [
					['id' => 1, 'name' => 'First'],
					['id' => 2, 'name' => 'Second']
				];
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 2] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'tables' => ['items'],
				'limit' => 2
			]
		);

		$outputPath = $this->tempDir . '/limited.json';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertFileExists( $outputPath );
		$data = json_decode( file_get_contents( $outputPath ), true );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'items', $data['data'] );
		$this->assertCount( 2, $data['data']['items']['rows'] );
	}

	/**
	 * Test table exclusion
	 */
	public function testTableExclusion(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock fetchAll to return multiple tables when querying information_schema
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'users'],
					['TABLE_NAME' => 'logs'],
					['TABLE_NAME' => 'posts']
				];
			}
			return [];
		} );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 0] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'exclude' => ['logs', 'phinx_log']
			]
		);

		$tableList = $exporter->getTableList();
		$this->assertNotContains( 'logs', $tableList );
		$this->assertNotContains( 'phinx_log', $tableList );
		$this->assertContains( 'users', $tableList );
		$this->assertContains( 'posts', $tableList );
	}

	/**
	 * Test compression option
	 */
	public function testCompressionOption(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'data']
				];
			}
			// Return data for the data table
			if( strpos( $sql, 'SELECT * FROM `data`' ) !== false )
			{
				return [
					['id' => 1, 'data' => 'test data']
				];
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 1] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'tables' => ['data'],
				'compress' => true
			]
		);

		$outputPath = $this->tempDir . '/compressed.json';
		$result = $exporter->exportToFile( $outputPath );

		// Should return the .gz file path
		$this->assertStringEndsWith( '.gz', $result );
		$this->assertFileExists( $result );

		// Decompress and verify content
		$compressed = file_get_contents( $result );
		$decompressed = gzdecode( $compressed );
		$data = json_decode( $decompressed, true );

		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'data', $data['data'] );
		$this->assertEquals( 'test data', $data['data']['data']['rows'][0]['data'] );
	}

	/**
	 * Test SQL-specific options (include_schema, drop_tables, use_transaction)
	 */
	public function testSqlSpecificOptions(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'test']
				];
			}
			// Return data for the test table
			if( strpos( $sql, 'SELECT * FROM `test`' ) !== false )
			{
				return [
					['id' => 1, 'name' => 'test']
				];
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 1] );
		// Remove this line - hasForeignKeys doesn't exist in AdapterInterface

		// Mock for CREATE TABLE statement
		$mockAdapter->method( 'getColumns' )->willReturn( [] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'sql',
				'tables' => ['test'],
				'include_schema' => true,
				'drop_tables' => true,
				'use_transaction' => true
			]
		);

		$outputPath = $this->tempDir . '/full.sql';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertFileExists( $outputPath );
		$content = file_get_contents( $outputPath );

		$this->assertStringContainsString( 'BEGIN', $content );
		$this->assertStringContainsString( 'COMMIT', $content );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $content );
	}

	/**
	 * Test streaming large datasets
	 */
	public function testStreamingLargeDatasets(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Generate large dataset
		$largeDataset = array_map( function( $i ) {
			return ['id' => $i, 'data' => "row_{$i}"];
		}, range( 1, 2000 ) );

		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) use ( $largeDataset ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'large_table']
				];
			}
			// Return data for the large_table
			if( strpos( $sql, 'SELECT * FROM `large_table`' ) !== false )
			{
				return $largeDataset;
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 2000] );
		// Remove this line - hasForeignKeys doesn't exist in AdapterInterface

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'sql',
				'tables' => ['large_table']
			]
		);

		$outputPath = $this->tempDir . '/large.sql';
		$result = $exporter->exportToFile( $outputPath );

		$this->assertFileExists( $outputPath );
		$size = filesize( $outputPath );
		$this->assertGreaterThan( 10000, $size ); // Should be a reasonably large file

		// Verify both batches are included
		$content = file_get_contents( $outputPath );
		$this->assertStringContainsString( 'row_1', $content );
		$this->assertStringContainsString( 'row_1000', $content );
		$this->assertStringContainsString( 'row_1001', $content );
		$this->assertStringContainsString( 'row_2000', $content );
	}

	/**
	 * Test disconnection on destruction
	 */
	public function testDisconnectionOnDestruction(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Expect disconnect to be called
		$mockAdapter->expects( $this->once() )->method( 'disconnect' );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log'
		);

		// Explicitly destroy to trigger destructor
		unset( $exporter );
	}

	/**
	 * Test error handling for invalid WHERE clause
	 */
	public function testInvalidWhereClauseThrowsException(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock fetchAll to return a table with users
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'users']
				];
			}
			return [];
		} );

		$this->mockAdapterFactory( $mockAdapter );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Potentially dangerous WHERE clause' );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'where' => ['users' => "1=1; DROP TABLE users--"]
			]
		);

		// Try to export - should throw exception during WHERE validation
		$outputPath = $this->tempDir . '/should_fail.json';
		$exporter->exportToFile( $outputPath );
	}

	// Helper methods

	private function createMockAdapter()
	{
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )  // Add getConnection method
			->getMock();

		// Create a mock PDO object
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			// Simulate PDO::quote behavior
			$escaped = str_replace( ["'", "\\"], ["''", "\\\\"], $value );
			return "'{$escaped}'";
		} );

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );

		return $mockAdapter;
	}

	private function createPartialMockAdapter()
	{
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
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
				'default_migration_table' => 'phinx_log',
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
