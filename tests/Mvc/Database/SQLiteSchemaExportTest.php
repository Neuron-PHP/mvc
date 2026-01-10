<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that SQLite getTableCreateStatement works correctly with fixed parameter binding
 */
class SQLiteSchemaExportTest extends TestCase
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
	 * Test SQLite getTableCreateStatement with PDO
	 */
	public function testSQLiteGetTableCreateStatementWithPDO(): void
	{
		// Create mock PDO
		$mockPdo = $this->createMock( \PDO::class );
		$mockStmt = $this->createMock( \PDOStatement::class );

		$expectedSql = "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)";

		$mockPdo->expects( $this->once() )
			->method( 'prepare' )
			->with( "SELECT sql FROM sqlite_master WHERE type='table' AND name=?" )
			->willReturn( $mockStmt );

		$mockStmt->expects( $this->once() )
			->method( 'execute' )
			->with( ['users'] )
			->willReturn( true );

		$mockStmt->expects( $this->once() )
			->method( 'fetch' )
			->with( \PDO::FETCH_ASSOC )
			->willReturn( ['sql' => $expectedSql] );

		// Create mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test.db' );

		$this->mockAdapterFactory( $mockAdapter );

		// Test DataExporter
		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to call private getTableCreateStatement method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTableCreateStatement' );
		$method->setAccessible( true );

		$result = $method->invoke( $exporter, 'users' );

		// Verify we got the expected CREATE TABLE statement
		$this->assertEquals( $expectedSql . ";", $result );
	}

	/**
	 * Test SQLite getTableCreateStatement fallback without PDO
	 */
	public function testSQLiteGetTableCreateStatementFallback(): void
	{
		$executedQueries = [];
		$expectedSql = "CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)";

		// Create mock adapter without PDO support
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );

		// Capture the SQL query executed
		$mockAdapter->method( 'fetchRow' )
			->willReturnCallback( function( $sql ) use ( &$executedQueries, $expectedSql ) {
				$executedQueries[] = $sql;
				// Verify the query doesn't contain a ? placeholder
				$this->assertStringNotContainsString( '?', $sql );
				// Verify it contains the escaped table name
				$this->assertStringContainsString( "name='posts'", $sql );
				return ['sql' => $expectedSql];
			} );

		$this->mockAdapterFactory( $mockAdapter );

		// Test DataExporter
		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to call private getTableCreateStatement method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTableCreateStatement' );
		$method->setAccessible( true );

		$result = $method->invoke( $exporter, 'posts' );

		// Verify we got the expected CREATE TABLE statement
		$this->assertEquals( $expectedSql . ";", $result );
		// Verify a query was executed
		$this->assertNotEmpty( $executedQueries );
	}

	/**
	 * Test handling of table names with special characters
	 */
	public function testSQLiteTableNameEscaping(): void
	{
		$executedQueries = [];

		// Create mock adapter
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );

		$mockAdapter->method( 'fetchRow' )
			->willReturnCallback( function( $sql ) use ( &$executedQueries ) {
				$executedQueries[] = $sql;
				// Table name with apostrophe should be escaped by doubling
				$this->assertStringContainsString( "name='user''s_table'", $sql );
				return ['sql' => "CREATE TABLE user's_table (id INTEGER)"];
			} );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTableCreateStatement' );
		$method->setAccessible( true );

		$result = $method->invoke( $exporter, "user's_table" );

		// Verify the result includes the table definition
		$this->assertStringContainsString( "CREATE TABLE", $result );
		$this->assertNotEmpty( $executedQueries );
	}

	/**
	 * Test handling of non-existent tables
	 */
	public function testSQLiteNonExistentTable(): void
	{
		// Create mock PDO
		$mockPdo = $this->createMock( \PDO::class );
		$mockStmt = $this->createMock( \PDOStatement::class );

		$mockPdo->expects( $this->once() )
			->method( 'prepare' )
			->willReturn( $mockStmt );

		$mockStmt->expects( $this->once() )
			->method( 'execute' )
			->with( ['nonexistent'] )
			->willReturn( true );

		$mockStmt->expects( $this->once() )
			->method( 'fetch' )
			->willReturn( false ); // No result for non-existent table

		// Create mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTableCreateStatement' );
		$method->setAccessible( true );

		$result = $method->invoke( $exporter, 'nonexistent' );

		// Should return fallback comment
		$this->assertStringContainsString( "CREATE TABLE statement not available", $result );
		$this->assertStringContainsString( "nonexistent", $result );
	}

	/**
	 * Test that MySQL is not affected
	 */
	public function testMySQLNotAffected(): void
	{
		// Create mock adapter for MySQL
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'fetchRow' )
			->with( $this->stringContains( 'SHOW CREATE TABLE' ) )
			->willReturn( ['Create Table' => 'CREATE TABLE test_table (id INT)'] );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'getTableCreateStatement' );
		$method->setAccessible( true );

		$result = $method->invoke( $exporter, 'test_table' );

		// MySQL should still work with SHOW CREATE TABLE
		$this->assertStringContainsString( "CREATE TABLE test_table", $result );
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
					'adapter' => 'sqlite',
					'name' => 'test.db'
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
