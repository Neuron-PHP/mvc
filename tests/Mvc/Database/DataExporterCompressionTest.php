<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Test compression functionality in DataExporter
 * Specifically tests the fix for streaming exports with compression
 */
class DataExporterCompressionTest extends TestCase
{
	private $tempDir;
	private $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp directory for test files
		$this->tempDir = sys_get_temp_dir() . '/compression_test_' . uniqid();
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
	 * Test that streaming exports with compression use gzwrite correctly
	 */
	public function testStreamingCompressionUsesGzwrite(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock the getTables query for MySQL
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			// Return table list for information_schema query
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'test_table']
				];
			}
			// Return large dataset to trigger streaming (first batch)
			if( strpos( $sql, 'LIMIT 1000 OFFSET 0' ) !== false )
			{
				$rows = [];
				for( $i = 1; $i <= 1000; $i++ )
				{
					$rows[] = ['id' => $i, 'data' => "test_data_{$i}"];
				}
				return $rows;
			}
			// Return more data (subsequent batches)
			if( preg_match( '/LIMIT 1000 OFFSET (\d+)/', $sql, $matches ) )
			{
				$offset = (int)$matches[1];
				if( $offset < 10000 )
				{
					$rows = [];
					for( $i = $offset + 1; $i <= min( $offset + 1000, 10001 ); $i++ )
					{
						$rows[] = ['id' => $i, 'data' => "test_data_{$i}"];
					}
					return $rows;
				}
			}
			// Default: return empty
			return [];
		} );

		// Mock row count to trigger streaming
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 10001] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'sql',
				'compress' => true // Enable compression
			]
		);

		$outputPath = $this->tempDir . '/streamed_compressed.sql';
		$result = $exporter->exportToFile( $outputPath );

		// Should return the .gz file path
		$this->assertStringEndsWith( '.gz', $result );
		$this->assertFileExists( $result );

		// The file should be a valid gzip file
		$compressed = file_get_contents( $result );
		$this->assertNotFalse( $compressed );

		// Should be able to decompress it
		$decompressed = gzdecode( $compressed );
		$this->assertNotFalse( $decompressed );

		// Should contain SQL content
		$this->assertStringContainsString( '-- Neuron PHP Database Data Dump', $decompressed );
		$this->assertStringContainsString( 'INSERT INTO', $decompressed );
		$this->assertStringContainsString( 'test_data_1', $decompressed );
		$this->assertStringContainsString( 'test_data_10000', $decompressed ); // Last row is 10001
		$this->assertStringContainsString( 'test_table', $decompressed );
	}

	/**
	 * Test CSV streaming with compression
	 */
	public function testCsvStreamingWithCompression(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$mockConfig = $this->createMockConfig();

		// Mock the getTables query
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [
					['TABLE_NAME' => 'products']
				];
			}
			// Return data for the table
			if( strpos( $sql, 'SELECT * FROM' ) !== false )
			{
				// Handle LIMIT/OFFSET for streaming
				preg_match( '/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $sql, $matches );
				$limit = isset( $matches[1] ) ? (int)$matches[1] : 10001;
				$offset = isset( $matches[2] ) ? (int)$matches[2] : 0;

				$rows = [];
				$start = $offset + 1;
				$end = min( $offset + $limit, 10001 );

				for( $i = $start; $i <= $end; $i++ )
				{
					$rows[] = [
						'id' => $i,
						'name' => "Product {$i}",
						'price' => number_format( $i * 10.99, 2 )
					];
				}
				return $rows;
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 10001] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter = new DataExporter(
			$mockConfig,
			'testing',
			'phinx_log',
			[
				'format' => 'csv',
				'compress' => true
			]
		);

		$outputPath = $this->tempDir . '/products.csv';
		$result = $exporter->exportToFile( $outputPath );

		// Should return the .gz file path
		$this->assertStringEndsWith( '.gz', $result );
		$this->assertFileExists( $result );

		// Decompress and verify CSV content
		$compressed = file_get_contents( $result );
		$decompressed = gzdecode( $compressed );
		$this->assertNotFalse( $decompressed );

		// Should contain CSV header and data
		$this->assertStringContainsString( '# Table: products', $decompressed );
		$this->assertStringContainsString( 'id,name,price', $decompressed );
		$this->assertStringContainsString( 'Product 1', $decompressed );
		$this->assertStringContainsString( '10.99', $decompressed );
	}

	// Helper methods

	private function createMockAdapter()
	{
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
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
		$mockAdapter->method( 'disconnect' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );

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
