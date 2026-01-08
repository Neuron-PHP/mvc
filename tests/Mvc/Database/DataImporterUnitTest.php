<?php

namespace Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Unit tests for DataImporter with mocked dependencies
 */
class DataImporterUnitTest extends TestCase
{
	/**
	 * Test FORMAT constants are defined
	 */
	public function testFormatConstantsDefined(): void
	{
		$this->assertEquals( 'sql', DataImporter::FORMAT_SQL );
		$this->assertEquals( 'json', DataImporter::FORMAT_JSON );
		$this->assertEquals( 'csv', DataImporter::FORMAT_CSV );
		$this->assertEquals( 'yaml', DataImporter::FORMAT_YAML );
	}

	/**
	 * Test CONFLICT mode constants are defined
	 */
	public function testConflictModeConstantsDefined(): void
	{
		$this->assertEquals( 'replace', DataImporter::CONFLICT_REPLACE );
		$this->assertEquals( 'append', DataImporter::CONFLICT_APPEND );
		$this->assertEquals( 'skip', DataImporter::CONFLICT_SKIP );
	}

	/**
	 * Test importFromFile checks if file exists
	 */
	public function testImportFromFileChecksFileExists(): void
	{
		// Skip this test as it requires specific IFileSystem interface methods
		$this->markTestSkipped( 'Requires IFileSystem interface methods' );
	}

	/**
	 * Test importFromFile handles compressed files
	 */
	public function testImportFromCompressedFile(): void
	{
		// Skip this test as it requires specific IFileSystem interface methods
		$this->markTestSkipped( 'Requires IFileSystem interface methods' );
	}

	/**
	 * Test different format options are accepted
	 */
	public function testDifferentFormatsAccepted(): void
	{
		$formats = ['sql', 'json', 'yaml'];

		foreach( $formats as $format )
		{
			// Create mocks
			$mockAdapter = $this->createMock( AdapterInterface::class );
			$mockConfig = $this->createMockConfig();

			// Configure adapter mock
			$mockAdapter->method( 'connect' );
			$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );

			// Mock AdapterFactory
			$this->mockAdapterFactory( $mockAdapter );

			// Create importer with format
			$importer = new DataImporter(
				$mockConfig,
				'testing',
				'phinx_log',
				['format' => $format]
			);

			// Just verify it was created successfully
			$this->assertInstanceOf( DataImporter::class, $importer );
		}
	}

	/**
	 * Test conflict resolution modes
	 */
	public function testConflictResolutionModes(): void
	{
		$modes = ['replace', 'append', 'skip'];

		foreach( $modes as $mode )
		{
			// Create mocks
			$mockAdapter = $this->createMock( AdapterInterface::class );
			$mockConfig = $this->createMockConfig();

			// Configure adapter mock
			$mockAdapter->method( 'connect' );
			$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

			// Mock AdapterFactory
			$this->mockAdapterFactory( $mockAdapter );

			// Create importer with conflict mode
			$importer = new DataImporter(
				$mockConfig,
				'testing',
				'phinx_log',
				['conflict_mode' => $mode]
			);

			$this->assertInstanceOf( DataImporter::class, $importer );
		}
	}

	/**
	 * Test table filtering options
	 */
	public function testTableFilteringOptions(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Test with table inclusion
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['tables' => ['users', 'posts']]
		);
		$this->assertInstanceOf( DataImporter::class, $importer );

		// Test with table exclusion
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['exclude' => ['logs', 'sessions']]
		);
		$this->assertInstanceOf( DataImporter::class, $importer );
	}

	/**
	 * Test transaction options
	 */
	public function testTransactionOptions(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Test transaction options
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'use_transaction' => true,
				'disable_foreign_keys' => true
			]
		);

		$this->assertInstanceOf( DataImporter::class, $importer );
	}

	/**
	 * Test batch size option
	 */
	public function testBatchSizeOption(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Test with batch size
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['batch_size' => 500]
		);

		$this->assertInstanceOf( DataImporter::class, $importer );
	}

	/**
	 * Test error handling options
	 */
	public function testErrorHandlingOptions(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Test stop on error
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['stop_on_error' => true]
		);
		$this->assertInstanceOf( DataImporter::class, $importer );

		// Test continue on error
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['stop_on_error' => false]
		);
		$this->assertInstanceOf( DataImporter::class, $importer );
	}

	/**
	 * Test getStatistics returns correct structure
	 */
	public function testGetStatisticsStructure(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Create importer
		$importer = new DataImporter( $mockConfig, 'testing' );

		// Get statistics
		$stats = $importer->getStatistics();

		// Verify structure
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'rows_imported', $stats );
		$this->assertArrayHasKey( 'tables_imported', $stats );
		$this->assertArrayHasKey( 'errors', $stats );

		// Initial values should be zero
		$this->assertEquals( 0, $stats['rows_imported'] );
		$this->assertEquals( 0, $stats['tables_imported'] );
		$this->assertEquals( 0, $stats['errors'] );
	}

	/**
	 * Test getErrors returns array
	 */
	public function testGetErrorsReturnsArray(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Create importer
		$importer = new DataImporter( $mockConfig, 'testing' );

		// Get errors
		$errors = $importer->getErrors();

		// Should be empty array initially
		$this->assertIsArray( $errors );
		$this->assertEmpty( $errors );
	}

	/**
	 * Test CSV import requires directory
	 */
	public function testCsvImportRequiresDirectory(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockFs = $this->createMock( IFileSystem::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Configure filesystem mock - not a directory
		$mockFs->expects( $this->once() )
			->method( 'isDir' )
			->with( '/test/csv' )
			->willReturn( false );

		// Create importer
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'csv'],
			$mockFs
		);

		// Expect exception
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Directory not found: /test/csv' );

		$importer->importFromCsvDirectory( '/test/csv' );
	}

	/**
	 * Test invalid JSON throws exception
	 */
	public function testInvalidJsonThrowsException(): void
	{
		// Create mocks - create a partial mock that adds hasTransaction method
		$methods = get_class_methods( AdapterInterface::class );
		$methods[] = 'hasTransaction';

		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->setMethods( $methods )
			->getMock();
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'rollbackTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( true );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Create importer
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'json']
		);

		// Expect exception
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid JSON' );

		$importer->import( '{invalid json}' );
	}

	/**
	 * Test invalid format throws exception
	 */
	public function testInvalidFormatThrowsException(): void
	{
		// Create mocks - create a partial mock that adds hasTransaction method
		$methods = get_class_methods( AdapterInterface::class );
		$methods[] = 'hasTransaction';

		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->setMethods( $methods )
			->getMock();
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'rollbackTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( true );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Create importer with invalid format
		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'invalid']
		);

		// Expect exception
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported format: invalid' );

		$importer->import( 'test data' );
	}

	/**
	 * Test clearAllData method exists
	 */
	public function testClearAllDataMethodExists(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'execute' );
		$mockAdapter->method( 'fetchAll' )->willReturn( [] );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Create importer
		$importer = new DataImporter( $mockConfig, 'testing' );

		// Call clearAllData
		$result = $importer->clearAllData( false );

		$this->assertTrue( $result );
	}

	/**
	 * Test verifyImport method exists
	 */
	public function testVerifyImportMethodExists(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 10] );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Create importer
		$importer = new DataImporter( $mockConfig, 'testing' );

		// Call verifyImport
		$results = $importer->verifyImport( ['users' => 10] );

		$this->assertIsArray( $results );
		$this->assertArrayHasKey( 'users', $results );
		$this->assertEquals( 10, $results['users']['expected'] );
		$this->assertEquals( 10, $results['users']['actual'] );
		$this->assertTrue( $results['users']['match'] );
	}

	/**
	 * Test destructor disconnects adapter
	 */
	public function testDestructorDisconnects(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Expect disconnect to be called during destruction
		$mockAdapter->expects( $this->atLeastOnce() )
			->method( 'disconnect' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Create importer
		$importer = new DataImporter( $mockConfig, 'testing' );

		// Explicitly call destructor
		$importer->__destruct();
	}

	/**
	 * Test progress callback option
	 */
	public function testProgressCallbackOption(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Test with progress callback
		$called = false;
		$callback = function( $current, $total ) use ( &$called ) {
			$called = true;
		};

		$importer = new DataImporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['progress_callback' => $callback]
		);

		$this->assertInstanceOf( DataImporter::class, $importer );
	}

	/**
	 * Create a mock Phinx config
	 *
	 * @return Config
	 */
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

	/**
	 * Mock the AdapterFactory to return our mock adapter
	 *
	 * @param AdapterInterface $mockAdapter
	 */
	private function mockAdapterFactory( AdapterInterface $mockAdapter ): void
	{
		// Use reflection to replace the factory instance
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );

		$mockFactory = $this->createMock( AdapterFactory::class );
		$mockFactory->method( 'getAdapter' )
			->willReturn( $mockAdapter );

		$instanceProperty->setValue( null, $mockFactory );
	}
}