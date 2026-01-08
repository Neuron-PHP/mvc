<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Comprehensive tests for DataImporter to improve code coverage
 */
class DataImporterComprehensiveTest extends TestCase
{
	private $tempDir;
	private $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp directory for test files
		$this->tempDir = sys_get_temp_dir() . '/dataimporter_test_' . uniqid();
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
	 * Test import from SQL file
	 */
	public function testImportFromSqlFile(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create test SQL file
		$sqlFile = $this->tempDir . '/import.sql';
		file_put_contents( $sqlFile, "
			INSERT INTO users (id, name, email) VALUES (1, 'John', 'john@example.com');
			INSERT INTO users (id, name, email) VALUES (2, 'Jane', 'jane@example.com');
		" );

		// Mock execute for SQL statements - returns affected rows count
		// With foreign key disabling: FK disable + 2 INSERTs + FK enable = 4 calls
		$mockAdapter->expects( $this->exactly( 4 ) )
			->method( 'execute' )
			->willReturn( 1 );

		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'sql']
		);

		$result = $importer->importFromFile( $sqlFile );
		$this->assertTrue( $result );

		$stats = $importer->getStatistics();
		$this->assertEquals( 2, $stats['rows_imported'] );
	}

	/**
	 * Test import from JSON file
	 */
	public function testImportFromJsonFile(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create test JSON file
		$jsonFile = $this->tempDir . '/import.json';
		$data = [
			'data' => [
				'users' => [
					['id' => 1, 'name' => 'Alice', 'active' => true],
					['id' => 2, 'name' => 'Bob', 'active' => false]
				],
				'posts' => [
					['id' => 1, 'title' => 'First Post', 'user_id' => 1],
					['id' => 2, 'title' => 'Second Post', 'user_id' => 2]
				]
			]
		];
		file_put_contents( $jsonFile, json_encode( $data, JSON_PRETTY_PRINT ) );

		// Mock for table clearing and data insertion
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'json']
		);

		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result );

		$stats = $importer->getStatistics();
		$this->assertEquals( 4, $stats['rows_imported'] ); // 2 users + 2 posts
		$this->assertEquals( 2, $stats['tables_imported'] );
	}

	/**
	 * Test import from YAML file
	 */
	public function testImportFromYamlFile(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create test YAML file
		$yamlFile = $this->tempDir . '/import.yaml';
		$yamlContent = "
data:
  users:
    - id: 1
      name: Charlie
      role: admin
    - id: 2
      name: David
      role: user
  categories:
    - id: 1
      name: Technology
    - id: 2
      name: Science
";
		file_put_contents( $yamlFile, $yamlContent );

		// Mock for table operations
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'yaml']
		);

		$result = $importer->importFromFile( $yamlFile );
		$this->assertTrue( $result );

		$stats = $importer->getStatistics();
		$this->assertEquals( 4, $stats['rows_imported'] ); // 2 users + 2 categories
		$this->assertEquals( 2, $stats['tables_imported'] );
	}

	/**
	 * Test import from CSV directory
	 */
	public function testImportFromCsvDirectory(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create CSV directory with files
		$csvDir = $this->tempDir . '/csv';
		mkdir( $csvDir );

		// Create products.csv
		$productsCsv = "id,name,price\n1,Widget,19.99\n2,Gadget,29.99\n";
		file_put_contents( $csvDir . '/products.csv', $productsCsv );

		// Create orders.csv
		$ordersCsv = "id,product_id,quantity\n1,1,5\n2,2,3\n";
		file_put_contents( $csvDir . '/orders.csv', $ordersCsv );

		// Mock for CSV import
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'csv']
		);

		// Import CSV files from directory
		$result = $importer->importFromCsvDirectory( $csvDir );

		$this->assertTrue( $result );

		$stats = $importer->getStatistics();
		$this->assertEquals( 4, $stats['rows_imported'] ); // 2 products + 2 orders
		$this->assertEquals( 2, $stats['tables_imported'] ); // products and orders tables
	}

	/**
	 * Test conflict modes (replace, append, skip)
	 */
	public function testConflictModes(): void
	{
		// Test REPLACE mode
		$this->testConflictMode( DataImporter::CONFLICT_REPLACE );

		// Test APPEND mode
		$this->testConflictMode( DataImporter::CONFLICT_APPEND );

		// Test SKIP mode
		$this->testConflictMode( DataImporter::CONFLICT_SKIP );
	}

	private function testConflictMode( string $mode ): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		$jsonFile = $this->tempDir . "/conflict_{$mode}.json";
		file_put_contents( $jsonFile, json_encode( ['data' => ['users' => [['id' => 1, 'name' => 'Test']]]] ) );

		// Different expectations based on mode
		if( $mode === DataImporter::CONFLICT_REPLACE )
		{
			// When replacing, foreign keys are disabled, table is cleared, then re-enabled
			// So we don't check for specific DELETE, just that execute is called multiple times
			$mockAdapter->method( 'execute' )->willReturn( 1 );
		}
		elseif( $mode === DataImporter::CONFLICT_SKIP )
		{
			// Expect to check if table has data
			$mockAdapter->method( 'fetchRow' )
				->willReturn( ['count' => 1] ); // Table has data, should skip
			$mockAdapter->method( 'execute' )->willReturn( 1 );
		}
		else
		{
			// APPEND mode - just insert
			$mockAdapter->method( 'execute' )->willReturn( 1 );
		}

		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'json', 'conflict_mode' => $mode]
		);

		$result = $importer->importFromFile( $jsonFile );

		if( $mode === DataImporter::CONFLICT_SKIP )
		{
			// Should skip import - check that no rows were imported
			$stats = $importer->getStatistics();
			$this->assertEquals( 0, $stats['rows_imported'] );
		}
		else
		{
			$this->assertTrue( $result );
			// For REPLACE and APPEND modes, verify data was imported
			$stats = $importer->getStatistics();
			$this->assertGreaterThan( 0, $stats['rows_imported'] );
		}
	}

	/**
	 * Test transaction handling
	 */
	public function testTransactionHandling(): void
	{
		$mockAdapter = $this->createPartialMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create test file
		$sqlFile = $this->tempDir . '/transaction.sql';
		file_put_contents( $sqlFile, "INSERT INTO test (id) VALUES (1);" );

		// Test with transaction enabled
		$mockAdapter->expects( $this->once() )->method( 'beginTransaction' );
		$mockAdapter->expects( $this->once() )->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );
		$mockAdapter->method( 'execute' )->willReturn( 1 );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'sql', 'use_transaction' => true]
		);

		$result = $importer->importFromFile( $sqlFile );
		$this->assertTrue( $result );
	}

	/**
	 * Test error handling with stop_on_error
	 */
	public function testStopOnError(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create SQL with multiple statements
		$sqlFile = $this->tempDir . '/errors.sql';
		file_put_contents( $sqlFile, "
			INSERT INTO test1 (id) VALUES (1);
			INSERT INTO test2 (id) VALUES (2);
			INSERT INTO test3 (id) VALUES (3);
		" );

		// Mock execute to fail on certain statements
		$callCount = 0;
		$mockAdapter->method( 'execute' )
			->willReturnCallback( function( $sql ) use ( &$callCount ) {
				// Check for foreign key statements first
				if( strpos( $sql, 'FOREIGN_KEY_CHECKS' ) !== false )
					return 1;

				// Check for INSERT statements
				if( strpos( $sql, 'INSERT' ) !== false )
				{
					$callCount++;
					if( $callCount == 1 )  // First INSERT succeeds
						return 1;
					if( $callCount == 2 )  // Second INSERT fails
						throw new \Exception( 'Test error' );
				}
				return 1;  // Any other calls
			} );

		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'rollbackTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( true );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'sql', 'stop_on_error' => false] // Set to false to capture errors without throwing
		);

		$result = $importer->importFromFile( $sqlFile );
		$this->assertFalse( $result );

		$errors = $importer->getErrors();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Test error', $errors[0] );

		// Verify we got errors - with stop_on_error false, it continues after error
		// So first INSERT succeeds, second fails, third may succeed too
		$stats = $importer->getStatistics();
		$this->assertGreaterThanOrEqual( 1, $stats['rows_imported'] );
	}

	/**
	 * Test foreign key disabling
	 */
	public function testForeignKeyDisabling(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		$sqlFile = $this->tempDir . '/foreign_keys.sql';
		file_put_contents( $sqlFile, "INSERT INTO child (parent_id) VALUES (1);" );

		// Expect foreign key checks to be disabled, INSERT, then re-enabled (3 calls)
		$mockAdapter->expects( $this->exactly( 3 ) )
			->method( 'execute' )
			->withConsecutive(
				[$this->stringContains( 'FOREIGN_KEY_CHECKS = 0' )], // Disable FK
				[$this->stringContains( 'INSERT' )],                  // Insert data
				[$this->stringContains( 'FOREIGN_KEY_CHECKS = 1' )]   // Re-enable FK
			)
			->willReturn( 1 );

		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'sql', 'disable_foreign_keys' => true]
		);

		$result = $importer->importFromFile( $sqlFile );
		$this->assertTrue( $result );
	}

	/**
	 * Test batch size handling
	 */
	public function testBatchSizeHandling(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create large SQL file with many statements
		$sqlFile = $this->tempDir . '/batch.sql';
		$statements = [];
		for( $i = 1; $i <= 150; $i++ )
		{
			$statements[] = "INSERT INTO test (id, value) VALUES ({$i}, 'value_{$i}');";
		}
		file_put_contents( $sqlFile, implode( "\n", $statements ) );

		// SQL import uses single transaction for entire file, not per batch
		$mockAdapter->expects( $this->once() )->method( 'beginTransaction' );
		$mockAdapter->expects( $this->once() )->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );
		$mockAdapter->method( 'execute' )->willReturn( 1 );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'sql', 'batch_size' => 50, 'use_transaction' => true]
		);

		$result = $importer->importFromFile( $sqlFile );
		$this->assertTrue( $result );

		$stats = $importer->getStatistics();
		$this->assertEquals( 150, $stats['rows_imported'] );
	}

	/**
	 * Test progress callback
	 */
	public function testProgressCallback(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		$sqlFile = $this->tempDir . '/progress.sql';
		file_put_contents( $sqlFile, "
			INSERT INTO test (id) VALUES (1);
			INSERT INTO test (id) VALUES (2);
			INSERT INTO test (id) VALUES (3);
		" );

		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		$progressCalls = [];
		$callback = function( $current, $total ) use ( &$progressCalls ) {
			$progressCalls[] = ['current' => $current, 'total' => $total];
		};

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'sql', 'progress_callback' => $callback]
		);

		$result = $importer->importFromFile( $sqlFile );
		$this->assertTrue( $result );

		// Verify callback was called
		$this->assertNotEmpty( $progressCalls );
		$this->assertEquals( 3, end( $progressCalls )['current'] );
		$this->assertEquals( 3, end( $progressCalls )['total'] );
	}

	/**
	 * Test clearAllData method
	 */
	public function testClearAllData(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock fetchAll to return tables when querying information_schema
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Handle getTables() query for MySQL
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'users'],
					['TABLE_NAME' => 'posts']
				];
			}
			return [];
		} );

		// Expect DELETE for each table (except migration table) and foreign key statements
		// The order will be: disable FK, DELETE users, DELETE posts, enable FK
		$mockAdapter->expects( $this->exactly( 4 ) )
			->method( 'execute' )
			->withConsecutive(
				[$this->stringContains( 'SET FOREIGN_KEY_CHECKS = 0' )],
				[$this->stringContains( 'DELETE FROM `users`' )],
				[$this->stringContains( 'DELETE FROM `posts`' )],
				[$this->stringContains( 'SET FOREIGN_KEY_CHECKS = 1' )]
			)
			->willReturn( 1 );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter( $mockConfig, 'testing', 'phinx_log' );
		$result = $importer->clearAllData( false );

		$this->assertTrue( $result );
	}

	/**
	 * Test verifyImport method
	 */
	public function testVerifyImport(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock table existence and counts
		$mockAdapter->method( 'hasTable' )
			->willReturnMap( [
				['users', true],
				['posts', true],
				['missing', false]
			] );

		$mockAdapter->method( 'fetchRow' )
			->willReturnCallback( function( $sql ) {
				if( strpos( $sql, '`users`' ) !== false )
				{
					return ['count' => 10];
				}
				if( strpos( $sql, '`posts`' ) !== false )
				{
					return ['count' => 5];
				}
				return false;
			} );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter( $mockConfig, 'testing' );

		$expectedCounts = [
			'users' => 10,
			'posts' => 5,
			'missing' => 3
		];

		$results = $importer->verifyImport( $expectedCounts );

		$this->assertArrayHasKey( 'users', $results );
		$this->assertEquals( 10, $results['users']['expected'] );
		$this->assertEquals( 10, $results['users']['actual'] );
		$this->assertTrue( $results['users']['match'] );

		$this->assertArrayHasKey( 'posts', $results );
		$this->assertEquals( 5, $results['posts']['expected'] );
		$this->assertEquals( 5, $results['posts']['actual'] );
		$this->assertTrue( $results['posts']['match'] );

		$this->assertArrayHasKey( 'missing', $results );
		$this->assertEquals( 3, $results['missing']['expected'] );
		$this->assertEquals( 0, $results['missing']['actual'] );
		$this->assertFalse( $results['missing']['match'] );
	}

	/**
	 * Test compressed file import
	 */
	public function testCompressedFileImport(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create compressed SQL file
		$sqlContent = "INSERT INTO test (id) VALUES (1);\nINSERT INTO test (id) VALUES (2);";
		$compressedFile = $this->tempDir . '/import.sql.gz';
		file_put_contents( $compressedFile, gzencode( $sqlContent ) );

		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'sql']
		);

		$result = $importer->importFromFile( $compressedFile );
		$this->assertTrue( $result );

		$stats = $importer->getStatistics();
		$this->assertEquals( 2, $stats['rows_imported'] );
	}

	// Helper methods

	private function createMockAdapter()
	{
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['hasTransaction', 'getConnection'] )
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
			->addMethods( ['hasTransaction'] )
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
