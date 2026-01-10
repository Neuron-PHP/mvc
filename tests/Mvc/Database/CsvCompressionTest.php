<?php

namespace Tests\Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Mvc\Database\DataExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that CSV export properly honors compression settings
 */
class CsvCompressionTest extends TestCase
{
	private $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

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
	 * Test that writeCsvToHandle uses writeToHandle wrapper consistently
	 */
	public function testWriteCsvToHandleUsesWrapper(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to test private methods
		$reflector = new \ReflectionClass( $exporter );
		$writeCsvMethod = $reflector->getMethod( 'writeCsvToHandle' );
		$writeCsvMethod->setAccessible( true );
		$formatCsvMethod = $reflector->getMethod( 'formatCsvLine' );
		$formatCsvMethod->setAccessible( true );

		// Test data
		$testRow = ['id' => 1, 'name' => 'Test', 'value' => '00123'];

		// Format the expected CSV line
		$expectedCsvLine = $formatCsvMethod->invoke( $exporter, $testRow );

		// Create a temp file to test writing
		$tempFile = tempnam( sys_get_temp_dir(), 'csv_test' );
		$handle = fopen( $tempFile, 'w' );

		try
		{
			// Write the CSV row
			$result = $writeCsvMethod->invoke( $exporter, $handle, $testRow );

			// Close and read back
			fclose( $handle );
			$written = file_get_contents( $tempFile );

			// Verify the content matches expected format
			$this->assertEquals( $expectedCsvLine, $written );
			$this->assertGreaterThan( 0, $result );
		}
		finally
		{
			if( is_resource( $handle ) )
			{
				fclose( $handle );
			}
			unlink( $tempFile );
		}
	}

	/**
	 * Test compressed CSV export
	 */
	public function testCompressedCsvExport(): void
	{
		// Create large dataset to trigger streaming mode
		$largeData = [];
		for( $i = 1; $i <= 11000; $i++ ) // > 10000 rows to trigger streaming
		{
			$largeData[] = [
				'id' => $i,
				'name' => "Product $i",
				'code' => str_pad( $i, 5, '0', STR_PAD_LEFT )
			];
		}

		// Create custom mock adapter
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );

		// Return large data to trigger streaming
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) use ( $largeData ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [['TABLE_NAME' => 'products']];
			}

			// Handle LIMIT/OFFSET queries for streaming
			preg_match( '/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $sql, $matches );
			$limit = isset( $matches[1] ) ? (int)$matches[1] : count( $largeData );
			$offset = isset( $matches[2] ) ? (int)$matches[2] : 0;

			// Return the appropriate slice of data
			return array_slice( $largeData, $offset, $limit );
		} );

		$mockAdapter->method( 'fetchRow' )
			->willReturn( ['count' => 11000] ); // Large count to trigger streaming

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataExporter::FORMAT_CSV,
			'compress' => true // Enable compression
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		// Export to compressed file - exporter will add .gz extension
		$tempFile = tempnam( sys_get_temp_dir(), 'csv_test' ) . '.csv';
		$actualFile = $exporter->exportToFile( $tempFile );

		try
		{
			$this->assertNotFalse( $actualFile, "Export should return actual file path" );
			$this->assertEquals( $tempFile . '.gz', $actualFile, "Should add .gz extension" );
			$this->assertFileExists( $actualFile );

			// Verify it's actually compressed
			$compressedContent = file_get_contents( $actualFile );
			$this->assertNotEmpty( $compressedContent );

			// Should be gzip compressed (starts with gzip magic number)
			$this->assertEquals( "\x1f\x8b", substr( $compressedContent, 0, 2 ),
				"File should start with gzip magic number" );

			// Decompress and verify content
			$decompressed = gzdecode( $compressedContent );
			$this->assertNotFalse( $decompressed );

			// Should contain CSV data
			$this->assertStringContainsString( 'id,name,code', $decompressed );
			$this->assertStringContainsString( '00001', $decompressed ); // First item
			$this->assertStringContainsString( '11000', $decompressed ); // Last item
		}
		finally
		{
			if( isset( $actualFile ) && file_exists( $actualFile ) )
			{
				unlink( $actualFile );
			}
		}
	}

	/**
	 * Test streaming CSV export with compression
	 */
	public function testStreamingCsvWithCompression(): void
	{
		// Mock large dataset to trigger streaming
		$largeData = [];
		for( $i = 1; $i <= 100; $i++ )
		{
			$largeData[] = [
				'id' => $i,
				'name' => "Product $i",
				'code' => str_pad( $i, 5, '0', STR_PAD_LEFT )
			];
		}

		// Create custom mock adapter for this test
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );

		// Return data with proper LIMIT/OFFSET handling
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) use ( $largeData ) {
			// Handle LIMIT/OFFSET queries
			preg_match( '/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $sql, $matches );
			if( isset( $matches[1] ) )
			{
				$limit = (int)$matches[1];
				$offset = isset( $matches[2] ) ? (int)$matches[2] : 0;
				return array_slice( $largeData, $offset, $limit );
			}
			return $largeData;
		} );
		$mockAdapter->method( 'fetchRow' )
			->willReturn( ['count' => 100] );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataExporter::FORMAT_CSV,
			'compress' => true
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		// Use reflection to test streaming
		$reflector = new \ReflectionClass( $exporter );
		$streamMethod = $reflector->getMethod( 'streamCsvTable' );
		$streamMethod->setAccessible( true );

		// Create compressed file handle
		$tempFile = tempnam( sys_get_temp_dir(), 'stream_csv' ) . '.gz';
		$handle = gzopen( $tempFile, 'w9' );

		try
		{
			// Stream the CSV table
			$streamMethod->invoke( $exporter, $handle, 'products' );
			gzclose( $handle );

			// Verify compressed file exists
			$this->assertFileExists( $tempFile );

			// Decompress and verify
			$decompressed = gzdecode( file_get_contents( $tempFile ) );
			$this->assertNotFalse( $decompressed );

			// Should contain header comment and CSV data
			$this->assertStringContainsString( '# Table: products', $decompressed );
			$this->assertStringContainsString( 'id,name,code', $decompressed );
			$this->assertStringContainsString( '00001', $decompressed );
			$this->assertStringContainsString( '00100', $decompressed );
		}
		finally
		{
			if( file_exists( $tempFile ) )
			{
				unlink( $tempFile );
			}
		}
	}

	// Helper methods

	private function createMockAdapter(): AdapterInterface
	{
		// Create mock PDO for escaping
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 2] );

		// Mock getTables for MySQL
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [['TABLE_NAME' => 'products']];
			}
			// Return default test data
			return [
				['id' => 1, 'name' => 'Test 1', 'code' => '00123'],
				['id' => 2, 'name' => 'Test 2', 'code' => '00456']
			];
		} );

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
}
