<?php

namespace Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use org\bovigo\vfs\vfsStream;

/**
 * Unit tests for DataImporter
 */
class DataImporterTest extends TestCase
{
	private $root;

	protected function setUp(): void
	{
		parent::setUp();

		// Set up virtual file system
		$this->root = vfsStream::setup( 'test' );
	}

	/**
	 * Test that importer can be instantiated with valid config
	 */
	public function testInstantiationWithValidConfig()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing' );

		$this->assertInstanceOf( DataImporter::class, $importer );
	}

	/**
	 * Test that importer accepts all format options
	 */
	public function testImporterAcceptsAllFormats()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$formats = [
			DataImporter::FORMAT_SQL,
			DataImporter::FORMAT_JSON,
			DataImporter::FORMAT_CSV,
			DataImporter::FORMAT_YAML
		];

		// CSV format is special - it requires directory import
		foreach( $formats as $format )
		{
			if( $format === DataImporter::FORMAT_CSV )
			{
				continue;
			}

			$options = ['format' => $format];
			$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );
			$this->assertInstanceOf( DataImporter::class, $importer );
		}
	}

	/**
	 * Test conflict resolution modes
	 */
	public function testConflictModes()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$modes = [
			DataImporter::CONFLICT_REPLACE,
			DataImporter::CONFLICT_APPEND,
			DataImporter::CONFLICT_SKIP
		];

		foreach( $modes as $mode )
		{
			$options = ['conflict_mode' => $mode];
			$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );
			$this->assertInstanceOf( DataImporter::class, $importer );
		}
	}

	/**
	 * Test import from SQL format
	 */
	public function testImportFromSql()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = ['format' => DataImporter::FORMAT_SQL];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$sql = "
			-- Test SQL
			BEGIN;
			DELETE FROM users;
			INSERT INTO users (id, name) VALUES (1, 'John'), (2, 'Jane');
			COMMIT;
		";

		$result = $importer->import( $sql );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		$this->assertGreaterThan( 0, $stats['rows_imported'] );
	}

	/**
	 * Test import from JSON format
	 */
	public function testImportFromJson()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = ['format' => DataImporter::FORMAT_JSON];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$json = json_encode( [
			'metadata' => [
				'exported_at' => date( 'Y-m-d H:i:s' ),
				'database_type' => 'sqlite'
			],
			'data' => [
				'users' => [
					['id' => 1, 'name' => 'John'],
					['id' => 2, 'name' => 'Jane']
				]
			]
		] );

		$result = $importer->import( $json );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		$this->assertEquals( 2, $stats['rows_imported'] );
	}

	/**
	 * Test import from YAML format
	 */
	public function testImportFromYaml()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = ['format' => DataImporter::FORMAT_YAML];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$yaml = "
metadata:
  exported_at: '2024-01-01 00:00:00'
  database_type: sqlite
data:
  users:
    - id: 1
      name: John
    - id: 2
      name: Jane
";

		$result = $importer->import( $yaml );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		$this->assertEquals( 2, $stats['rows_imported'] );
	}

	/**
	 * Test import from file
	 */
	public function testImportFromFile()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing' );

		// Create test file
		$filePath = vfsStream::url( 'test/data.sql' );
		file_put_contents( $filePath, "INSERT INTO users (id, name) VALUES (1, 'Test');" );

		$result = $importer->importFromFile( $filePath );

		$this->assertTrue( $result );
	}

	/**
	 * Test import from compressed file
	 */
	public function testImportFromCompressedFile()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing' );

		// Create compressed test file
		$sql = "INSERT INTO users (id, name) VALUES (1, 'Test');";
		$compressed = gzencode( $sql );
		$filePath = vfsStream::url( 'test/data.sql.gz' );
		file_put_contents( $filePath, $compressed );

		$result = $importer->importFromFile( $filePath );

		$this->assertTrue( $result );
	}

	/**
	 * Test import with table filtering
	 */
	public function testImportWithTableFiltering()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = [
			'tables' => ['users'],
			'format' => DataImporter::FORMAT_JSON
		];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$json = json_encode( [
			'data' => [
				'users' => [
					['id' => 1, 'name' => 'John']
				],
				'posts' => [
					['id' => 1, 'title' => 'Test']
				]
			]
		] );

		$result = $importer->import( $json );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		// Should only import users table
		$this->assertEquals( 1, $stats['tables_imported'] );
	}

	/**
	 * Test import with table exclusion
	 */
	public function testImportWithTableExclusion()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = [
			'exclude' => ['logs', 'sessions'],
			'format' => DataImporter::FORMAT_JSON
		];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$json = json_encode( [
			'data' => [
				'users' => [
					['id' => 1, 'name' => 'John']
				],
				'logs' => [
					['id' => 1, 'message' => 'Test']
				]
			]
		] );

		$result = $importer->import( $json );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		// Should skip logs table
		$this->assertEquals( 1, $stats['tables_imported'] );
	}

	/**
	 * Test CSV directory import
	 */
	public function testImportFromCsvDirectory()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = ['format' => DataImporter::FORMAT_CSV];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		// Create CSV files
		$dirPath = vfsStream::url( 'test/csv' );
		mkdir( $dirPath );

		// Users CSV
		$usersCsv = "id,name\n1,John\n2,Jane";
		file_put_contents( $dirPath . '/users.csv', $usersCsv );

		// Metadata
		$metadata = json_encode( [
			'exported_at' => date( 'Y-m-d H:i:s' ),
			'tables' => ['users.csv']
		] );
		file_put_contents( $dirPath . '/export_metadata.json', $metadata );

		$result = $importer->importFromCsvDirectory( $dirPath );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		$this->assertEquals( 1, $stats['tables_imported'] );
		$this->assertEquals( 2, $stats['rows_imported'] );
	}

	/**
	 * Test transaction rollback on error
	 */
	public function testTransactionRollback()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataImporter::FORMAT_SQL,
			'use_transaction' => true,
			'stop_on_error' => true
		];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		// SQL with error
		$sql = "
			BEGIN;
			INSERT INTO users (id, name) VALUES (1, 'John');
			INSERT INTO invalid_table (id) VALUES (1);
			COMMIT;
		";

		$this->expectException( \Exception::class );
		$importer->import( $sql );

		// Verify transaction was rolled back
		$stats = $importer->getStatistics();
		$this->assertEquals( 0, $stats['rows_imported'] );
	}

	/**
	 * Test continue on error mode
	 */
	public function testContinueOnError()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataImporter::FORMAT_SQL,
			'stop_on_error' => false,
			'use_transaction' => false
		];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		// SQL with some errors
		$sql = "
			INSERT INTO users (id, name) VALUES (1, 'John');
			INSERT INTO invalid_table (id) VALUES (1);
			INSERT INTO users (id, name) VALUES (2, 'Jane');
		";

		$result = $importer->import( $sql );

		$this->assertFalse( $result ); // Has errors
		$errors = $importer->getErrors();
		$this->assertNotEmpty( $errors );

		// But some data was imported
		$stats = $importer->getStatistics();
		$this->assertGreaterThan( 0, $stats['rows_imported'] );
	}

	/**
	 * Test batch insert functionality
	 */
	public function testBatchInsert()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataImporter::FORMAT_JSON,
			'batch_size' => 2
		];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		// Create data with more rows than batch size
		$rows = [];
		for( $i = 1; $i <= 5; $i++ )
		{
			$rows[] = ['id' => $i, 'name' => "User{$i}"];
		}

		$json = json_encode( [
			'data' => [
				'users' => $rows
			]
		] );

		$result = $importer->import( $json );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		$this->assertEquals( 5, $stats['rows_imported'] );
	}

	/**
	 * Test clear all data functionality
	 */
	public function testClearAllData()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing' );

		$result = $importer->clearAllData( false );

		$this->assertTrue( $result );
	}

	/**
	 * Test format auto-detection
	 */
	public function testFormatAutoDetection()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing' );

		// Test SQL detection
		$sqlFile = vfsStream::url( 'test/data.unknown' );
		file_put_contents( $sqlFile, "INSERT INTO users VALUES (1);" );
		$result = $importer->importFromFile( $sqlFile );
		$this->assertTrue( $result );

		// Test JSON detection
		$jsonFile = vfsStream::url( 'test/data2.unknown' );
		file_put_contents( $jsonFile, '{"data": {"users": []}}' );
		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result );
	}

	/**
	 * Test invalid format throws exception
	 */
	public function testInvalidFormatThrowsException()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported format: invalid' );

		$config = $this->createMockConfig();
		$options = ['format' => 'invalid'];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$importer->import( 'test data' );
	}

	/**
	 * Test invalid JSON throws exception
	 */
	public function testInvalidJsonThrowsException()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid JSON' );

		$config = $this->createMockConfig();
		$options = ['format' => DataImporter::FORMAT_JSON];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$importer->import( '{invalid json}' );
	}

	/**
	 * Test file not found throws exception
	 */
	public function testFileNotFoundThrowsException()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'File not found' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing' );

		$importer->importFromFile( '/nonexistent/file.sql' );
	}

	/**
	 * Test migration table is excluded by default
	 */
	public function testMigrationTableExcludedByDefault()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = ['format' => DataImporter::FORMAT_SQL];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$sql = "
			INSERT INTO users (id, name) VALUES (1, 'John');
			INSERT INTO phinx_log (version) VALUES (20240101000000);
		";

		$result = $importer->import( $sql );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		// phinx_log insert should be skipped
		$this->assertEquals( 1, $stats['rows_imported'] );
	}

	/**
	 * Test migration table can be explicitly included
	 */
	public function testMigrationTableCanBeIncluded()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataImporter::FORMAT_SQL,
			'tables' => ['phinx_log', 'users']
		];
		$importer = new DataImporter( $config, 'testing', 'phinx_log', $options );

		$sql = "
			INSERT INTO users (id, name) VALUES (1, 'John');
			INSERT INTO phinx_log (version) VALUES (20240101000000);
		";

		$result = $importer->import( $sql );

		$this->assertTrue( $result );
		$stats = $importer->getStatistics();
		// Both inserts should be processed
		$this->assertEquals( 2, $stats['rows_imported'] );
	}

	/**
	 * Test import verification
	 */
	public function testVerifyImport()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing' );

		// Import some data first
		$json = json_encode( [
			'data' => [
				'users' => [
					['id' => 1, 'name' => 'John'],
					['id' => 2, 'name' => 'Jane']
				]
			]
		] );

		$importer->import( $json );

		// Verify
		$results = $importer->verifyImport( [
			'users' => 2
		] );

		$this->assertArrayHasKey( 'users', $results );
		$this->assertEquals( 2, $results['users']['expected'] );
		$this->assertEquals( 2, $results['users']['actual'] );
		$this->assertTrue( $results['users']['match'] );
	}

	/**
	 * Create a mock Phinx config for testing
	 *
	 * @return Config
	 */
	private function createMockConfig(): Config
	{
		return new Config( [
			'paths' => [
				'migrations' => vfsStream::url( 'test/migrations' )
			],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => ':memory:'
				]
			]
		] );
	}
}