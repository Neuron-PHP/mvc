<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use Neuron\Mvc\Database\SchemaExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that getTables() method properly handles MySQL parameter binding
 */
class GetTablesFixTest extends TestCase
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
	 * Test that MySQL getTables uses PDO parameter binding correctly
	 */
	public function testMySQLGetTablesWithPDO(): void
	{
		// Track queries executed
		$executedQueries = [];

		// Create mock PDO
		$mockPdo = $this->createMock( \PDO::class );
		$mockStmt = $this->createMock( \PDOStatement::class );

		// Mock prepare/execute/fetchAll sequence
		$mockPdo->expects( $this->once() )
			->method( 'prepare' )
			->with( $this->stringContains( 'information_schema.TABLES' ) )
			->willReturn( $mockStmt );

		$mockStmt->expects( $this->once() )
			->method( 'execute' )
			->with( ['test_db'] )
			->willReturn( true );

		$mockStmt->expects( $this->once() )
			->method( 'fetchAll' )
			->with( \PDO::FETCH_ASSOC )
			->willReturn( [
				['TABLE_NAME' => 'users'],
				['TABLE_NAME' => 'posts'],
				['TABLE_NAME' => 'comments']
			] );

		// Create mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection', 'hasTransaction'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'getOption' )->with( 'name' )->willReturn( 'test_db' );

		$this->mockAdapterFactory( $mockAdapter );

		// Test DataExporter
		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to call private getTables method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTables' );
		$method->setAccessible( true );

		$tables = $method->invoke( $exporter );

		// Verify we got the expected tables
		$this->assertEquals( ['users', 'posts', 'comments'], $tables );
	}

	/**
	 * Test MySQL getTables fallback when PDO is not available
	 */
	public function testMySQLGetTablesFallbackNoPDO(): void
	{
		$executedQueries = [];

		// Create mock adapter without PDO support
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'getOption' )->with( 'name' )->willReturn( 'test_db' );

		// Capture the SQL query executed
		$mockAdapter->method( 'fetchAll' )
			->willReturnCallback( function( $sql ) use ( &$executedQueries ) {
				$executedQueries[] = $sql;
				// Verify the query doesn't contain a ? placeholder
				$this->assertStringNotContainsString( '?', $sql );
				// Verify it contains the escaped database name
				$this->assertStringContainsString( "WHERE TABLE_SCHEMA = 'test_db'", $sql );
				return [
					['TABLE_NAME' => 'orders'],
					['TABLE_NAME' => 'products']
				];
			} );

		$this->mockAdapterFactory( $mockAdapter );

		// Test DataExporter
		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to call private getTables method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTables' );
		$method->setAccessible( true );

		$tables = $method->invoke( $exporter );

		// Verify we got tables
		$this->assertEquals( ['orders', 'products'], $tables );
		// Verify a query was executed
		$this->assertNotEmpty( $executedQueries );
	}

	/**
	 * Test SchemaExporter with MySQL
	 */
	public function testSchemaExporterMySQLGetTables(): void
	{
		// Create mock PDO
		$mockPdo = $this->createMock( \PDO::class );
		$mockStmt = $this->createMock( \PDOStatement::class );

		$mockPdo->expects( $this->once() )
			->method( 'prepare' )
			->willReturn( $mockStmt );

		$mockStmt->expects( $this->once() )
			->method( 'execute' )
			->with( ['schema_test'] )
			->willReturn( true );

		$mockStmt->expects( $this->once() )
			->method( 'fetchAll' )
			->willReturn( [
				['TABLE_NAME' => 'articles'],
				['TABLE_NAME' => 'categories']
			] );

		// Create mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'getOption' )->with( 'name' )->willReturn( 'schema_test' );

		$this->mockAdapterFactory( $mockAdapter );

		// Test SchemaExporter
		$config = $this->createMockConfig();
		$exporter = new SchemaExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to call private getTables method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTables' );
		$method->setAccessible( true );

		$tables = $method->invoke( $exporter );

		// Verify we got the expected tables
		$this->assertEquals( ['articles', 'categories'], $tables );
	}

	/**
	 * Test that PostgreSQL and SQLite don't use parameter binding
	 */
	public function testNonMySQLDatabasesDontUseParameterBinding(): void
	{
		// Test PostgreSQL
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'pgsql' );
		$mockAdapter->method( 'fetchAll' )
			->with( $this->stringContains( 'pg_catalog.pg_tables' ) )
			->willReturn( [
				['tablename' => 'pg_table1']
			] );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTables' );
		$method->setAccessible( true );
		$tables = $method->invoke( $exporter );

		$this->assertEquals( ['pg_table1'], $tables );

		// Test SQLite
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );
		$mockAdapter->method( 'fetchAll' )
			->with( $this->stringContains( 'sqlite_master' ) )
			->willReturn( [
				['name' => 'sqlite_table1']
			] );

		$this->mockAdapterFactory( $mockAdapter );

		$exporter2 = new DataExporter( $config, 'testing', 'phinx_log' );
		$tables2 = $method->invoke( $exporter2 );

		$this->assertEquals( ['sqlite_table1'], $tables2 );
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
