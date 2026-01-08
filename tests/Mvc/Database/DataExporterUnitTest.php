<?php

namespace Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Mvc\Database\DataExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Unit tests for DataExporter with mocked dependencies
 */
class DataExporterUnitTest extends TestCase
{
	/**
	 * Test FORMAT constants are defined
	 */
	public function testFormatConstantsDefined(): void
	{
		$this->assertEquals( 'sql', DataExporter::FORMAT_SQL );
		$this->assertEquals( 'json', DataExporter::FORMAT_JSON );
		$this->assertEquals( 'csv', DataExporter::FORMAT_CSV );
		$this->assertEquals( 'yaml', DataExporter::FORMAT_YAML );
	}

	/**
	 * Test exportToFile creates directory if needed
	 */
	public function testExportToFileCreatesDirectory(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockFs = $this->createMock( IFileSystem::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->expects( $this->once() )
			->method( 'connect' );

		$mockAdapter->expects( $this->once() )
			->method( 'getAdapterType' )
			->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Configure filesystem mock
		$mockFs->expects( $this->once() )
			->method( 'isDir' )
			->with( '/test/db' )
			->willReturn( false );

		$mockFs->expects( $this->once() )
			->method( 'mkdir' )
			->with( '/test/db', 0755, true )
			->willReturn( true );

		$mockFs->expects( $this->once() )
			->method( 'writeFile' )
			->willReturn( 100 );

		// Configure adapter to return empty table list
		$mockAdapter->expects( $this->any() )
			->method( 'fetchAll' )
			->willReturn( [] );

		// Create exporter with mocked dependencies
		$exporter = new DataExporter( $mockConfig, 'testing', 'phinx_log', [], $mockFs );

		// Test export
		$result = $exporter->exportToFile( '/test/db/dump.sql' );

		$this->assertTrue( $result );
	}

	/**
	 * Test export with compression adds .gz extension
	 */
	public function testExportWithCompression(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockFs = $this->createMock( IFileSystem::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Configure filesystem mock
		$mockFs->method( 'isDir' )->willReturn( true );

		$mockFs->expects( $this->once() )
			->method( 'writeFile' )
			->with( $this->stringContains( '.gz' ) )
			->willReturn( 100 );

		// Configure adapter to return empty table list
		$mockAdapter->method( 'fetchAll' )->willReturn( [] );

		// Create exporter with compression
		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['compress' => true],
			$mockFs
		);

		// Test export
		$result = $exporter->exportToFile( '/test/dump.sql' );

		$this->assertTrue( $result );
	}

	/**
	 * Test different format options are accepted
	 */
	public function testDifferentFormatsAccepted(): void
	{
		$formats = ['sql', 'json', 'csv', 'yaml'];

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

			// Create exporter with format
			$exporter = new DataExporter(
				$mockConfig,
				'testing',
				'phinx_log',
				['format' => $format]
			);

			// Just verify it was created successfully
			$this->assertInstanceOf( DataExporter::class, $exporter );
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
		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['tables' => ['users', 'posts']]
		);
		$this->assertInstanceOf( DataExporter::class, $exporter );

		// Test with table exclusion
		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['exclude' => ['logs', 'sessions']]
		);
		$this->assertInstanceOf( DataExporter::class, $exporter );
	}

	/**
	 * Test SQL-specific options
	 */
	public function testSqlSpecificOptions(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Test SQL options
		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'sql',
				'include_schema' => true,
				'drop_tables' => true,
				'use_transaction' => true
			]
		);

		$this->assertInstanceOf( DataExporter::class, $exporter );
	}

	/**
	 * Test export with limit option
	 */
	public function testExportWithLimit(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Test with row limit
		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['limit' => 100]
		);

		$this->assertInstanceOf( DataExporter::class, $exporter );
	}

	/**
	 * Test CSV directory export structure
	 */
	public function testCsvDirectoryExport(): void
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

		// Configure filesystem mock
		$mockFs->expects( $this->once() )
			->method( 'isDir' )
			->willReturn( false );

		$mockFs->expects( $this->once() )
			->method( 'mkdir' )
			->willReturn( true );

		// Expect metadata file write
		$mockFs->expects( $this->once() )
			->method( 'writeFile' )
			->with( $this->stringContains( 'export_metadata.json' ) )
			->willReturn( 100 );

		// Configure adapter to return empty table list
		$mockAdapter->method( 'fetchAll' )->willReturn( [] );

		// Create exporter
		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			['format' => 'csv'],
			$mockFs
		);

		// Test CSV export
		$result = $exporter->exportCsvToDirectory( '/test/csv' );

		$this->assertIsArray( $result );
		$this->assertContains( '/test/csv/export_metadata.json', $result );
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

		// Create exporter
		$exporter = new DataExporter( $mockConfig, 'testing' );

		// Explicitly call destructor
		$exporter->__destruct();
	}

	/**
	 * Test WHERE conditions option
	 */
	public function testWhereConditionsOption(): void
	{
		// Create mocks
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockConfig = $this->createMockConfig();

		// Configure adapter mock
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Mock AdapterFactory
		$this->mockAdapterFactory( $mockAdapter );

		// Test with WHERE conditions
		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'where' => [
					'users' => 'created_at > "2024-01-01"',
					'posts' => 'status = "published"'
				]
			]
		);

		$this->assertInstanceOf( DataExporter::class, $exporter );
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