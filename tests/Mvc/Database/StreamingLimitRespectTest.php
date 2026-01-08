<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that streaming export respects user's limit option even when triggered by large dataset
 */
class StreamingLimitRespectTest extends TestCase
{
	private $originalFactory;  // Changed from static to instance variable

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

		// Always reset to null for clean state, don't restore saved value
		// This ensures each test gets a fresh factory instance
		$instanceProperty->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Test that SQL streaming respects small limit even with large dataset
	 */
	public function testSqlStreamingRespectsSmallLimit(): void
	{
		// Create test data - 15000 rows to trigger streaming
		$testData = [];
		for( $i = 1; $i <= 15000; $i++ )
		{
			$testData[] = [
				'id' => $i,
				'name' => "User{$i}",
				'email' => "user{$i}@test.com"
			];
		}

		// Mock PDO for SQL escaping
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		// Mock adapter
		$fetchCount = 0;
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo ); // Return mock PDO

		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) use ( &$fetchCount, $testData ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [['TABLE_NAME' => 'users']];
			}

			// Extract LIMIT and OFFSET from SQL
			preg_match( '/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $sql, $matches );
			$limit = isset( $matches[1] ) ? (int)$matches[1] : 1000;
			$offset = isset( $matches[2] ) ? (int)$matches[2] : 0;

			// Track how many fetches
			$fetchCount++;

			// The limit should be 50 for the first batch when user requests 50 rows
			if( $fetchCount === 1 )
			{
				$this->assertEquals( 50, $limit, "First batch should fetch exactly 50 rows when limit is 50" );
			}

			// Return appropriate chunk
			return array_slice( $testData, $offset, $limit );
		} );

		$mockAdapter->method( 'fetchRow' )
			->willReturn( ['count' => 15000] ); // Large count to trigger streaming

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataExporter::FORMAT_SQL,
			'limit' => 50  // Request only 50 rows
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		// Use reflection to test private streamSqlTable method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'streamSqlTable' );
		$method->setAccessible( true );

		// Create temp file for output
		$tempFile = tempnam( sys_get_temp_dir(), 'sql_limit_test' );
		$handle = fopen( $tempFile, 'w' );

		try
		{
			$method->invoke( $exporter, $handle, 'users' );
			fclose( $handle );

			// Read back the SQL
			$sqlContent = file_get_contents( $tempFile );

			// Count INSERT statements
			preg_match_all( '/INSERT INTO/', $sqlContent, $matches );
			$insertCount = count( $matches[0] );

			// Should have exactly 1 INSERT statement (batch insert for 50 rows)
			$this->assertEquals( 1, $insertCount, "Should have exactly 1 INSERT statement for 50 rows" );

			// Count actual value sets in INSERT statement
			// Format: INSERT INTO table (...) VALUES (...), (...), ...
			preg_match( '/VALUES\s+(.*);/s', $sqlContent, $valueMatch );
			if( isset( $valueMatch[1] ) )
			{
				// Count opening parentheses for value sets
				$valueCount = substr_count( $valueMatch[1], '(' );
				$this->assertEquals( 50, $valueCount, "Should have exactly 50 value sets in INSERT" );
			}

			// Verify only one fetch was made (since we only needed 50 rows)
			$this->assertEquals( 1, $fetchCount, "Should only fetch once when limit is less than batch size" );

			// Verify the last user ID is 50, not 1000
			$this->assertStringContainsString( 'User50', $sqlContent );
			$this->assertStringNotContainsString( 'User51', $sqlContent );
			$this->assertStringNotContainsString( 'User1000', $sqlContent );
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
	 * Test that CSV streaming respects small limit even with large dataset
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testCsvStreamingRespectsSmallLimit(): void
	{
		// Create test data - 12000 rows to trigger streaming
		$testData = [];
		for( $i = 1; $i <= 12000; $i++ )
		{
			$testData[] = [
				'id' => $i,
				'product' => "Product{$i}",
				'price' => $i * 10
			];
		}

		// Mock adapter
		$fetchCount = 0;
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( null );

		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) use ( &$fetchCount, $testData ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [['TABLE_NAME' => 'products']];
			}

			// Extract LIMIT and OFFSET
			preg_match( '/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $sql, $matches );
			$limit = isset( $matches[1] ) ? (int)$matches[1] : 1000;
			$offset = isset( $matches[2] ) ? (int)$matches[2] : 0;

			$fetchCount++;

			// First batch should be limited to 75 rows
			if( $fetchCount === 1 )
			{
				$this->assertEquals( 75, $limit, "First batch should fetch exactly 75 rows when limit is 75" );
			}

			return array_slice( $testData, $offset, $limit );
		} );

		$mockAdapter->method( 'fetchRow' )
			->willReturn( ['count' => 12000] );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataExporter::FORMAT_CSV,
			'limit' => 75  // Request only 75 rows
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'streamCsvTable' );
		$method->setAccessible( true );

		$tempFile = tempnam( sys_get_temp_dir(), 'csv_limit_test' );
		$handle = fopen( $tempFile, 'w' );

		try
		{
			$method->invoke( $exporter, $handle, 'products' );
			fclose( $handle );

			// Read and parse CSV
			$csvContent = file_get_contents( $tempFile );
			$lines = explode( "\n", trim( $csvContent ) );

			// Remove comment line
			$lines = array_filter( $lines, function( $line ) {
				return !empty( $line ) && $line[0] !== '#';
			} );

			// Should have header + 75 data rows = 76 lines
			$this->assertCount( 76, $lines, "Should have header + 75 data rows" );

			// Verify the last product is Product75
			$lastLine = end( $lines );
			$this->assertStringContainsString( 'Product75', $lastLine );

			// Verify Product76 doesn't exist
			$this->assertStringNotContainsString( 'Product76', $csvContent );
			$this->assertStringNotContainsString( 'Product1000', $csvContent );

			// Should only fetch once
			$this->assertEquals( 1, $fetchCount, "Should only fetch once when limit is less than batch size" );
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
	 * Test streaming with limit spanning multiple batches
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testStreamingWithLimitSpanningBatches(): void
	{
		// Test with limit of 2500 rows (spans 3 batches: 1000 + 1000 + 500)
		$testData = [];
		for( $i = 1; $i <= 20000; $i++ )
		{
			$testData[] = [
				'id' => $i,
				'data' => "Data{$i}"
			];
		}

		$fetchCount = 0;
		$batchLimits = [];

		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( null );

		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) use ( &$fetchCount, &$batchLimits, $testData ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [['TABLE_NAME' => 'data_table']];
			}

			preg_match( '/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $sql, $matches );
			$limit = isset( $matches[1] ) ? (int)$matches[1] : 1000;
			$offset = isset( $matches[2] ) ? (int)$matches[2] : 0;

			$fetchCount++;
			$batchLimits[] = $limit;

			return array_slice( $testData, $offset, $limit );
		} );

		$mockAdapter->method( 'fetchRow' )
			->willReturn( ['count' => 20000] );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataExporter::FORMAT_CSV,
			'limit' => 2500  // Spans multiple batches
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'streamCsvTable' );
		$method->setAccessible( true );

		$tempFile = tempnam( sys_get_temp_dir(), 'csv_batch_test' );
		$handle = fopen( $tempFile, 'w' );

		try
		{
			$method->invoke( $exporter, $handle, 'data_table' );
			fclose( $handle );

			// Verify fetch pattern
			$this->assertEquals( 3, $fetchCount, "Should fetch 3 times for 2500 rows" );
			$this->assertEquals( [1000, 1000, 500], $batchLimits, "Batch limits should be 1000, 1000, 500" );

			// Count rows in CSV
			$csvContent = file_get_contents( $tempFile );
			$lines = array_filter( explode( "\n", $csvContent ), function( $line ) {
				return !empty( $line ) && $line[0] !== '#';
			} );

			// Should have header + 2500 data rows = 2501 total
			$this->assertCount( 2501, $lines );

			// Verify last row is Data2500
			// Use array_values to re-index after filter
			$indexedLines = array_values( $lines );
			$lastDataLine = $indexedLines[count( $indexedLines ) - 1];
			$this->assertStringContainsString( 'Data2500', $lastDataLine );
			$this->assertStringNotContainsString( 'Data2501', $csvContent );
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
	 * Test that no limit still works correctly
	 */
	public function testStreamingWithNoLimit(): void
	{
		// Small dataset that still triggers streaming due to total row count check
		$testData = [];
		for( $i = 1; $i <= 11000; $i++ )
		{
			$testData[] = ['id' => $i, 'value' => "Val{$i}"];
		}

		// Mock PDO for SQL escaping
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		$fetchCount = 0;
		$totalRowsFetched = 0;

		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );

		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) use ( &$fetchCount, &$totalRowsFetched, $testData ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [['TABLE_NAME' => 'values']];
			}

			preg_match( '/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $sql, $matches );
			$limit = isset( $matches[1] ) ? (int)$matches[1] : 1000;
			$offset = isset( $matches[2] ) ? (int)$matches[2] : 0;

			$fetchCount++;

			// All batches should use default 1000 limit when no user limit
			$this->assertEquals( 1000, $limit, "Should use default batch size when no user limit" );

			$rows = array_slice( $testData, $offset, $limit );
			$totalRowsFetched += count( $rows );

			return $rows;
		} );

		$mockAdapter->method( 'fetchRow' )
			->willReturn( ['count' => 11000] );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataExporter::FORMAT_SQL
			// No limit specified
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'streamSqlTable' );
		$method->setAccessible( true );

		$tempFile = tempnam( sys_get_temp_dir(), 'sql_nolimit_test' );
		$handle = fopen( $tempFile, 'w' );

		try
		{
			$method->invoke( $exporter, $handle, 'values' );
			fclose( $handle );

			// Should fetch all data in batches of 1000
			// 11 fetches with data + 1 empty fetch to detect end = 12 total
			$this->assertEquals( 12, $fetchCount, "Should fetch 12 times (11 with data + 1 empty)" );
			$this->assertEquals( 11000, $totalRowsFetched, "Should fetch all 11000 rows" );
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

	// Helper methods

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