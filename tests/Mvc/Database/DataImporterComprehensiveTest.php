<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Test helper class that extends AdapterInterface with additional methods
 * needed for testing (hasTransaction, getConnection).
 *
 * This avoids the brittle approach of using addMethods() in getMockBuilder().
 */
abstract class TestAdapterWithExtras implements AdapterInterface
{
	/**
	 * Check if adapter is in a transaction
	 * @return bool
	 */
	abstract public function hasTransaction(): bool;

	/**
	 * Get the underlying PDO connection
	 * @return \PDO
	 */
	abstract public function getConnection(): \PDO;
}

/**
 * Comprehensive tests for DataImporter to improve code coverage
 */
class DataImporterComprehensiveTest extends TestCase
{
	private $tempDir;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp directory for test files
		$this->tempDir = sys_get_temp_dir() . '/dataimporter_test_' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		// Ensure clean state by resetting AdapterFactory at start of each test
		$this->resetAdapterFactoryInstance();
	}

	protected function tearDown(): void
	{
		// Clean up temporary directory
		if( isset( $this->tempDir ) && is_dir( $this->tempDir ) )
		{
			$this->recursiveRemoveDir( $this->tempDir );
		}

		// Reset AdapterFactory to null to ensure clean state
		$this->resetAdapterFactoryInstance();

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
		$this->assertArrayHasKey( 'rows_imported', $stats );
		$this->assertArrayHasKey( 'tables_imported', $stats );
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
		$this->assertArrayHasKey( 'rows_imported', $stats );
		$this->assertArrayHasKey( 'tables_imported', $stats );
		$this->assertEquals( 4, $stats['rows_imported'] ); // 2 users + 2 categories
		$this->assertEquals( 2, $stats['tables_imported'] );
	}

	/**
	 * Test import from CSV directory
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
		$this->assertArrayHasKey( 'rows_imported', $stats );
		$this->assertArrayHasKey( 'tables_imported', $stats );
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
			// SKIP mode with existing data (count=1) should return true (no-op success)
			// Contract: Returns true when operation completes without errors, even if all tables skipped
			// The skip is indicated by rows_imported=0, not by return value
			$this->assertTrue( $result, 'CONFLICT_SKIP should return true (no-op success) when tables are skipped without errors' );

			// Should skip import - check that no rows were imported
			$stats = $importer->getStatistics();
			$this->assertArrayHasKey( 'rows_imported', $stats );
			$this->assertEquals( 0, $stats['rows_imported'], 'CONFLICT_SKIP should import 0 rows when table has existing data' );
		}
		else
		{
			$this->assertTrue( $result );
			// For REPLACE and APPEND modes, verify data was imported
			$stats = $importer->getStatistics();
			$this->assertArrayHasKey( 'rows_imported', $stats );
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
	 * Test continue-on-error behavior (stop_on_error => false)
	 * When stop_on_error is false, import continues after errors
	 */
	public function testContinueOnError(): void
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
			['format' => 'sql', 'stop_on_error' => false] // Continue on error
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
	 * Test stop-on-error behavior (stop_on_error => true)
	 * When stop_on_error is true, import stops immediately and throws on first error
	 */
	public function testStopOnErrorThrows(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Create SQL with multiple statements
		$sqlFile = $this->tempDir . '/errors_stop.sql';
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
					if( $callCount == 2 )  // Second INSERT fails - should stop here
						throw new \Exception( 'Stop on error test' );
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
			['format' => 'sql', 'stop_on_error' => true] // Stop on error
		);

		// With stop_on_error => true, the exception should be re-thrown
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Stop on error test' );

		$importer->importFromFile( $sqlFile );
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

		// Capture SQL statements to verify foreign key checks
		$executedSql = [];
		$mockAdapter->expects( $this->exactly( 3 ) )
			->method( 'execute' )
			->willReturnCallback( function( $sql ) use ( &$executedSql ) {
				$executedSql[] = $sql;
				return 1;
			} );

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

		// Verify SQL execution order: disable FK, INSERT, re-enable FK
		$this->assertCount( 3, $executedSql );
		$this->assertStringContainsString( 'FOREIGN_KEY_CHECKS = 0', $executedSql[0] ); // Disable FK
		$this->assertStringContainsString( 'INSERT', $executedSql[1] );                  // Insert data
		$this->assertStringContainsString( 'FOREIGN_KEY_CHECKS = 1', $executedSql[2] ); // Re-enable FK
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

		// Capture SQL statements to verify DELETE order and foreign key handling
		$executedSql = [];
		$mockAdapter->expects( $this->exactly( 4 ) )
			->method( 'execute' )
			->willReturnCallback( function( $sql ) use ( &$executedSql ) {
				$executedSql[] = $sql;
				return 1;
			} );

		$this->mockAdapterFactory( $mockAdapter );

		// Test with disable_foreign_keys=false so FK checks are restored after clearing
		$importer = new DataImporter( $mockConfig, 'testing', 'phinx_log', ['disable_foreign_keys' => false] );
		$result = $importer->clearAllData( false );

		$this->assertTrue( $result );

		// Verify SQL execution order: disable FK, DELETE users, DELETE posts, enable FK
		$this->assertCount( 4, $executedSql );
		$this->assertStringContainsString( 'SET FOREIGN_KEY_CHECKS = 0', $executedSql[0] );
		$this->assertStringContainsString( 'DELETE FROM `users`', $executedSql[1] );
		$this->assertStringContainsString( 'DELETE FROM `posts`', $executedSql[2] );
		$this->assertStringContainsString( 'SET FOREIGN_KEY_CHECKS = 1', $executedSql[3] );
	}

	/**
	 * Test clearAllData with disable_foreign_keys enabled keeps FK checks disabled
	 * Regression test for bug where FK checks were re-enabled before import
	 */
	public function testClearAllDataWithDisableForeignKeys(): void
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

		// Capture SQL statements to verify DELETE order and foreign key handling
		$executedSql = [];
		$mockAdapter->expects( $this->exactly( 3 ) )
			->method( 'execute' )
			->willReturnCallback( function( $sql ) use ( &$executedSql ) {
				$executedSql[] = $sql;
				return 1;
			} );

		$this->mockAdapterFactory( $mockAdapter );

		// Test with disable_foreign_keys=true so FK checks remain disabled
		$importer = new DataImporter( $mockConfig, 'testing', 'phinx_log', ['disable_foreign_keys' => true] );
		$result = $importer->clearAllData( false );

		$this->assertTrue( $result );

		// Verify SQL execution order: disable FK, DELETE users, DELETE posts
		// FK checks should NOT be re-enabled when disable_foreign_keys option is true
		$this->assertCount( 3, $executedSql );
		$this->assertStringContainsString( 'SET FOREIGN_KEY_CHECKS = 0', $executedSql[0] );
		$this->assertStringContainsString( 'DELETE FROM `users`', $executedSql[1] );
		$this->assertStringContainsString( 'DELETE FROM `posts`', $executedSql[2] );

		// Verify FK checks were NOT re-enabled
		foreach( $executedSql as $sql )
		{
			$this->assertStringNotContainsString( 'SET FOREIGN_KEY_CHECKS = 1', $sql );
		}
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

	/**
	 * Reset AdapterFactory singleton instance via Reflection
	 *
	 * Encapsulates reflection logic with proper guards and error handling.
	 * This is necessary because AdapterFactory uses a singleton pattern and tests
	 * need to ensure a clean state between test runs.
	 *
	 * @return void
	 * @throws \ReflectionException If reflection fails
	 */
	protected function resetAdapterFactoryInstance(): void
	{
		try
		{
			$factoryClass = new \ReflectionClass( AdapterFactory::class );

			// Guard: Check if the 'instance' property exists
			if( !$factoryClass->hasProperty( 'instance' ) )
			{
				$this->markTestSkipped( 'AdapterFactory::$instance property does not exist - cannot reset singleton' );
				return;
			}

			$instanceProperty = $factoryClass->getProperty( 'instance' );

			// Guard: Check if property is accessible or can be made accessible
			if( !$instanceProperty->isPublic() )
			{
				$instanceProperty->setAccessible( true );
			}

			// Reset the singleton instance to null
			$instanceProperty->setValue( null, null );
		}
		catch( \ReflectionException $e )
		{
			$this->fail( 'Failed to reset AdapterFactory instance via reflection: ' . $e->getMessage() );
		}
	}

	private function createMockAdapter()
	{
		// Use TestAdapterWithExtras for the extra methods (hasTransaction, getConnection)
		$mockAdapter = $this->getMockBuilder( TestAdapterWithExtras::class )
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
		// Use TestAdapterWithExtras for the extra hasTransaction method
		$mockAdapter = $this->getMockBuilder( TestAdapterWithExtras::class )
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

	/**
	 * Recursively remove a directory and its contents
	 * Security: Skips symlinks and validates all paths are within the root directory
	 *
	 * @param string $dir Directory to remove
	 * @return void
	 */
	private function recursiveRemoveDir( $dir ): void
	{
		if( !is_dir( $dir ) ) return;

		// Get the canonical root path to validate all operations stay within bounds
		$root = realpath( $dir );
		if( $root === false )
		{
			return; // Invalid directory, skip
		}

		// Normalize root with trailing separator to prevent false positives
		// Example: /tmp/test/ will NOT match /tmp/test123/file.txt
		// but /tmp/test would incorrectly match it with simple strpos check
		$rootWithSeparator = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		$objects = scandir( $dir );
		foreach( $objects as $object )
		{
			if( $object != "." && $object != ".." )
			{
				$path = $dir . "/" . $object;

				// SECURITY: Detect symlinks and remove them without following
				// unlink() on a symlink removes the link itself, not the target
				if( is_link( $path ) )
				{
					unlink( $path ); // Safe: removes only the symlink, not its target
					continue;
				}

				// SECURITY: Validate path is within the intended directory tree
				// Use normalized paths with trailing separator to prevent sibling path false positives
				$realPath = realpath( $path );
				if( $realPath === false )
				{
					continue; // Path doesn't exist, skip
				}

				// Ensure realPath starts with rootWithSeparator OR equals root (for direct children)
				// This prevents /tmp/test123 from matching when root is /tmp/test
				$realPathWithSeparator = rtrim( $realPath, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
				if( strpos( $realPathWithSeparator, $rootWithSeparator ) !== 0 && $realPath !== $root )
				{
					continue; // Path is outside root, skip
				}

				if( is_dir( $path ) )
				{
					$this->recursiveRemoveDir( $path );
				}
				else
				{
					unlink( $path );
				}
			}
		}
		rmdir( $dir );
	}
}
