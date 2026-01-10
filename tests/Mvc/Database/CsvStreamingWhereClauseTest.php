<?php

namespace Tests\Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Mvc\Database\DataExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that CSV streaming export properly handles WHERE clause filtering
 */
class CsvStreamingWhereClauseTest extends TestCase
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
	 * Test that streamCsvTable applies WHERE clause filtering
	 */
	public function testStreamCsvTableAppliesWhereFilter(): void
	{
		// Create test data - 15000 rows to trigger streaming (>10000)
		$testData = [];
		$filteredData = [];

		for( $i = 1; $i <= 15000; $i++ )
		{
			$status = ( $i % 3 === 0 ) ? 'active' : 'inactive';
			$row = [
				'id' => $i,
				'name' => "User{$i}",
				'status' => $status
			];
			$testData[] = $row;

			// Track which rows should match filter
			if( $status === 'active' )
			{
				$filteredData[] = $row;
			}
		}

		// Setup mock adapter to return data in chunks
		$mockPdo = $this->createMock( \PDO::class );
		$mockStmt = $this->createMock( \PDOStatement::class );

		// Track how many times fetchAll is called and what offset is used
		$fetchCount = 0;
		$queriesExecuted = [];

		// Setup statement to track queries and return appropriate chunks
		$mockStmt->expects( $this->any() )
			->method( 'execute' )
			->willReturnCallback( function( $bindings ) use ( &$queriesExecuted ) {
				$queriesExecuted[] = $bindings;
				return true;
			} );

		$mockStmt->expects( $this->any() )
			->method( 'fetchAll' )
			->willReturnCallback( function() use ( &$fetchCount, $filteredData ) {
				$limit = 1000;
				$offset = $fetchCount * $limit;
				$fetchCount++;

				// Return appropriate chunk of filtered data
				return array_slice( $filteredData, $offset, $limit );
			} );

		$mockPdo->expects( $this->any() )
			->method( 'prepare' )
			->willReturnCallback( function( $sql ) use ( $mockStmt, &$queriesExecuted ) {
				// Verify WHERE clause is in the SQL
				$this->assertStringContainsString( 'WHERE', $sql );
				$this->assertStringContainsString( 'LIMIT', $sql );
				$this->assertStringContainsString( 'OFFSET', $sql );
				$queriesExecuted[] = ['sql' => $sql];
				return $mockStmt;
			} );

		// Create mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );

		// Mock fetchAll to return table list and row count for streaming check
		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [['TABLE_NAME' => 'users']];
			}
			return [];
		} );

		$mockAdapter->method( 'fetchRow' )
			->willReturn( ['count' => 15000] ); // Large count to trigger streaming

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataExporter::FORMAT_CSV,
			'where' => [
				'users' => "status = 'active'"
			]
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		// Use reflection to test private streamCsvTable method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'streamCsvTable' );
		$method->setAccessible( true );

		// Create temp file for output
		$tempFile = tempnam( sys_get_temp_dir(), 'csv_stream_test' );
		$handle = fopen( $tempFile, 'w' );

		try
		{
			// Stream the CSV with WHERE filtering
			$method->invoke( $exporter, $handle, 'users' );
			fclose( $handle );

			// Read back the CSV
			$csvContent = file_get_contents( $tempFile );

			// Verify content
			$this->assertStringContainsString( '# Table: users', $csvContent );
			$this->assertStringContainsString( 'id,name,status', $csvContent );

			// Count active users in output (every 3rd user)
			$activeCount = substr_count( $csvContent, 'active' );
			$inactiveCount = substr_count( $csvContent, 'inactive' );

			// Should have 5000 active users (15000 / 3)
			$this->assertEquals( 5000, $activeCount, "Should have 5000 active rows" );
			$this->assertEquals( 0, $inactiveCount, "Should have no inactive rows due to WHERE filter" );

			// Verify multiple queries were executed (streaming)
			$this->assertGreaterThan( 1, $fetchCount, "Should have made multiple fetches for streaming" );

			// Verify WHERE clause was used in queries
			foreach( $queriesExecuted as $query )
			{
				if( isset( $query['sql'] ) && strpos( $query['sql'], 'SELECT' ) === 0 )
				{
					$this->assertStringContainsString( 'WHERE', $query['sql'] );
				}
			}
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
	 * Test streaming without WHERE clause still works
	 */
	public function testStreamCsvTableWithoutWhereClause(): void
	{
		// Create smaller test data
		$testData = [];
		for( $i = 1; $i <= 12000; $i++ ) // Just over 10000 to trigger streaming
		{
			$testData[] = [
				'id' => $i,
				'name' => "Product{$i}",
				'price' => $i * 10
			];
		}

		// Setup mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( null ); // No PDO, fallback to direct SQL

		// Track fetch calls
		$fetchCount = 0;

		$mockAdapter->method( 'fetchAll' )->willReturnCallback( function( $sql ) use ( &$fetchCount, $testData ) {
			if( strpos( $sql, 'information_schema.TABLES' ) !== false )
			{
				return [['TABLE_NAME' => 'products']];
			}

			// Verify no WHERE clause in SELECT query
			if( strpos( $sql, 'SELECT' ) === 0 )
			{
				$this->assertStringNotContainsString( 'WHERE', $sql );
				$this->assertStringContainsString( 'LIMIT', $sql );
				$this->assertStringContainsString( 'OFFSET', $sql );

				// Parse limit and offset from SQL
				preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $sql, $matches );
				$limit = isset( $matches[1] ) ? (int)$matches[1] : 1000;
				$offset = isset( $matches[2] ) ? (int)$matches[2] : $fetchCount * 1000;

				$fetchCount++;
				return array_slice( $testData, $offset, $limit );
			}

			return [];
		} );

		$mockAdapter->method( 'fetchRow' )
			->willReturn( ['count' => 12000] );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'format' => DataExporter::FORMAT_CSV
			// No WHERE clause
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'streamCsvTable' );
		$method->setAccessible( true );

		$tempFile = tempnam( sys_get_temp_dir(), 'csv_stream_test' );
		$handle = fopen( $tempFile, 'w' );

		try
		{
			$method->invoke( $exporter, $handle, 'products' );
			fclose( $handle );

			$csvContent = file_get_contents( $tempFile );

			// Verify all data is present
			$this->assertStringContainsString( '# Table: products', $csvContent );
			$this->assertStringContainsString( 'Product1,', $csvContent );
			$this->assertStringContainsString( 'Product12000,', $csvContent );

			// Should have made multiple fetches for streaming
			$this->assertGreaterThan( 1, $fetchCount );
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
	 * Test that SQL and CSV streaming use same WHERE logic
	 */
	public function testSqlAndCsvStreamingConsistency(): void
	{
		$whereClause = "type = 'admin' OR status = 'active'";

		// Mock PDO and statement
		$mockPdo = $this->createMock( \PDO::class );
		$mockStmt = $this->createMock( \PDOStatement::class );

		$sqlQueries = [];
		$csvQueries = [];

		$mockPdo->expects( $this->any() )
			->method( 'prepare' )
			->willReturnCallback( function( $sql ) use ( $mockStmt, &$sqlQueries, &$csvQueries ) {
				// Track queries based on context (we'll know from the pattern)
				if( strpos( $sql, 'DELETE FROM' ) !== false )
				{
					// This is from SQL streaming
					$sqlQueries[] = $sql;
				}
				else
				{
					// This is from CSV streaming
					$csvQueries[] = $sql;
				}
				return $mockStmt;
			} );

		$mockStmt->expects( $this->any() )
			->method( 'execute' )
			->willReturn( true );

		$mockStmt->expects( $this->any() )
			->method( 'fetchAll' )
			->willReturn( [] ); // Empty result to stop streaming

		// Mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'fetchAll' )->willReturn( [['TABLE_NAME' => 'users']] );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 15000] );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$options = [
			'where' => ['users' => $whereClause]
		];

		$exporter = new DataExporter( $config, 'testing', 'phinx_log', $options );

		$reflector = new \ReflectionClass( $exporter );
		$streamSql = $reflector->getMethod( 'streamSqlTable' );
		$streamSql->setAccessible( true );
		$streamCsv = $reflector->getMethod( 'streamCsvTable' );
		$streamCsv->setAccessible( true );

		// Test SQL streaming
		$tempFile1 = tempnam( sys_get_temp_dir(), 'sql_test' );
		$handle1 = fopen( $tempFile1, 'w' );
		$streamSql->invoke( $exporter, $handle1, 'users' );
		fclose( $handle1 );

		// Test CSV streaming
		$tempFile2 = tempnam( sys_get_temp_dir(), 'csv_test' );
		$handle2 = fopen( $tempFile2, 'w' );
		$streamCsv->invoke( $exporter, $handle2, 'users' );
		fclose( $handle2 );

		// Clean up
		unlink( $tempFile1 );
		unlink( $tempFile2 );

		// Both methods should generate similar WHERE clauses
		// Find SELECT queries in both
		$sqlSelects = array_filter( $sqlQueries, function( $q ) {
			return strpos( $q, 'SELECT' ) !== false;
		} );

		$csvSelects = array_filter( $csvQueries, function( $q ) {
			return strpos( $q, 'SELECT' ) !== false;
		} );

		// Both should have WHERE clauses with parameterized queries
		foreach( array_merge( $sqlSelects, $csvSelects ) as $query )
		{
			$this->assertStringContainsString( 'WHERE', $query );
			$this->assertStringContainsString( '?', $query ); // Parameterized
			$this->assertMatchesRegularExpression( '/\s+(OR|AND)\s+/i', $query );
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
