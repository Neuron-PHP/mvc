<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use Neuron\Mvc\Database\DataExporterWithORM;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that WHERE clause parsing preserves AND/OR operator structure
 */
class WhereClauseParsingTest extends TestCase
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
	 * Test parsing WHERE clause with mixed AND/OR operators
	 */
	public function testMixedAndOrOperators(): void
	{
		// Create mock PDO
		$mockPdo = $this->createMock( \PDO::class );

		// Create mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to test private parseSimpleWhereClause method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseSimpleWhereClause' );
		$method->setAccessible( true );

		// Test case: a = 1 AND b = 2 OR c = 3
		$whereClause = "status = 'active' AND age > 18 OR role = 'admin'";
		$result = $method->invoke( $exporter, $whereClause );

		// Verify the structure is preserved
		$this->assertStringContainsString( 'AND', $result['sql'] );
		$this->assertStringContainsString( 'OR', $result['sql'] );

		// The SQL should be: `status` = ? AND `age` > ? OR `role` = ?
		// Not: `status` = ? OR `age` > ? OR `role` = ?
		$expectedPattern = '/.*\?\s+AND\s+.*\?\s+OR\s+.*\?/';
		$this->assertMatchesRegularExpression( $expectedPattern, $result['sql'] );

		// Verify bindings
		$this->assertEquals( ['active', '18', 'admin'], $result['bindings'] );
	}

	/**
	 * Test parsing complex WHERE clause with multiple AND/OR
	 */
	public function testComplexMixedOperators(): void
	{
		$mockPdo = $this->createMock( \PDO::class );
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseSimpleWhereClause' );
		$method->setAccessible( true );

		// Complex case: a = 1 OR b = 2 AND c = 3 OR d = 4
		$whereClause = "priority = 'high' OR status = 'pending' AND created > '2024-01-01' OR type = 'urgent'";
		$result = $method->invoke( $exporter, $whereClause );

		// Verify exact operator sequence
		$parts = preg_split( '/\s*\?\s*/', $result['sql'] );

		// Should have 3 operators between 4 conditions
		$this->assertStringContainsString( 'OR', $parts[1] );
		$this->assertStringContainsString( 'AND', $parts[2] );
		$this->assertStringContainsString( 'OR', $parts[3] );

		// Verify bindings
		$this->assertEquals( ['high', 'pending', '2024-01-01', 'urgent'], $result['bindings'] );
	}

	/**
	 * Test DataExporterWithORM parseWhereClause method
	 */
	public function testDataExporterWithORMParsing(): void
	{
		$mockPdo = $this->createMock( \PDO::class );
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		// Use reflection to test private parseWhereClause method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseWhereClause' );
		$method->setAccessible( true );

		// Test mixed operators
		$whereClause = "id = 5 AND name = 'test' OR status = 'active'";
		$result = $method->invoke( $exporter, $whereClause );

		// Verify structure preservation
		$this->assertStringContainsString( 'AND', $result['sql'] );
		$this->assertStringContainsString( 'OR', $result['sql'] );

		// Should not be all OR or all AND
		$this->assertStringNotContainsString( '? OR `name`', $result['sql'] );
		$this->assertStringContainsString( '? AND `name`', $result['sql'] );

		$this->assertEquals( ['5', 'test', 'active'], $result['bindings'] );
	}

	/**
	 * Test all AND operators
	 */
	public function testAllAndOperators(): void
	{
		$mockPdo = $this->createMock( \PDO::class );
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseSimpleWhereClause' );
		$method->setAccessible( true );

		$whereClause = "a = 1 AND b = 2 AND c = 3";
		$result = $method->invoke( $exporter, $whereClause );

		// Should have only AND operators
		$this->assertStringContainsString( 'AND', $result['sql'] );
		$this->assertStringNotContainsString( 'OR', $result['sql'] );

		// Count ANDs - should be 2 (between 3 conditions)
		$andCount = substr_count( $result['sql'], 'AND' );
		$this->assertEquals( 2, $andCount );

		$this->assertEquals( ['1', '2', '3'], $result['bindings'] );
	}

	/**
	 * Test all OR operators
	 */
	public function testAllOrOperators(): void
	{
		$mockPdo = $this->createMock( \PDO::class );
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseSimpleWhereClause' );
		$method->setAccessible( true );

		$whereClause = "a = 1 OR b = 2 OR c = 3";
		$result = $method->invoke( $exporter, $whereClause );

		// Should have only OR operators
		$this->assertStringContainsString( 'OR', $result['sql'] );
		$this->assertStringNotContainsString( 'AND', $result['sql'] );

		// Count ORs - should be 2 (between 3 conditions)
		$orCount = substr_count( $result['sql'], 'OR' );
		$this->assertEquals( 2, $orCount );

		$this->assertEquals( ['1', '2', '3'], $result['bindings'] );
	}

	/**
	 * Test single condition
	 */
	public function testSingleCondition(): void
	{
		$mockPdo = $this->createMock( \PDO::class );
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseSimpleWhereClause' );
		$method->setAccessible( true );

		$whereClause = "status = 'active'";
		$result = $method->invoke( $exporter, $whereClause );

		// Should have no operators
		$this->assertStringNotContainsString( 'AND', $result['sql'] );
		$this->assertStringNotContainsString( 'OR', $result['sql'] );

		// Should have one placeholder
		$this->assertStringContainsString( '`status` = ?', $result['sql'] );

		$this->assertEquals( ['active'], $result['bindings'] );
	}

	/**
	 * Test parsing WHERE clause with SQL-escaped single quotes (CVE-style fix)
	 *
	 * This tests the fix for a high-severity bug where the regex pattern
	 * ([^\'"]*)\3 would silently truncate values containing SQL-escaped quotes.
	 * For example, name = 'O''Brien' would only capture 'O' instead of 'O'Brien'.
	 */
	public function testSqlEscapedSingleQuotes(): void
	{
		$mockPdo = $this->createMock( \PDO::class );
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseSimpleWhereClause' );
		$method->setAccessible( true );

		// Test SQL-escaped single quote ('' represents a literal single quote)
		$whereClause = "name = 'O''Brien'";
		$result = $method->invoke( $exporter, $whereClause );

		// The value should be unescaped: O'Brien (not just O)
		$this->assertEquals( ["O'Brien"], $result['bindings'] );
		$this->assertStringContainsString( '`name` = ?', $result['sql'] );

		// Test multiple escaped quotes in one value
		$whereClause = "message = 'It''s John''s'";
		$result = $method->invoke( $exporter, $whereClause );

		$this->assertEquals( ["It's John's"], $result['bindings'] );

		// Test escaped quotes in a complex WHERE clause
		$whereClause = "name = 'O''Brien' AND status = 'active'";
		$result = $method->invoke( $exporter, $whereClause );

		$this->assertEquals( ["O'Brien", 'active'], $result['bindings'] );
		$this->assertStringContainsString( 'AND', $result['sql'] );
	}

	/**
	 * Test parsing WHERE clause with SQL-escaped double quotes
	 */
	public function testSqlEscapedDoubleQuotes(): void
	{
		$mockPdo = $this->createMock( \PDO::class );
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseSimpleWhereClause' );
		$method->setAccessible( true );

		// Test SQL-escaped double quote ("" represents a literal double quote)
		$whereClause = 'message = "He said ""Hello"""';
		$result = $method->invoke( $exporter, $whereClause );

		// The value should be unescaped: He said "Hello"
		$this->assertEquals( ['He said "Hello"'], $result['bindings'] );
		$this->assertStringContainsString( '`message` = ?', $result['sql'] );
	}

	/**
	 * Test that parentheses throw an exception
	 */
	public function testParenthesesThrowException(): void
	{
		$mockPdo = $this->createMock( \PDO::class );
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseSimpleWhereClause' );
		$method->setAccessible( true );

		$whereClause = "(a = 1 AND b = 2) OR c = 3";

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Parentheses are not supported' );

		$method->invoke( $exporter, $whereClause );
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
