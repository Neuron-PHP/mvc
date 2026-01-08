<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporterWithORM;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that parseWhereClause correctly preserves AND/OR operator structure
 */
class MixedWhereClauseTest extends TestCase
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
	 * Test that mixed AND/OR operators are preserved in correct order
	 */
	public function testMixedAndOrPreserved(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		// Use reflection to test private parseWhereClause method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseWhereClause' );
		$method->setAccessible( true );

		// Test case: status = 'active' AND type = 'user' OR deleted = '0'
		$whereClause = "status = 'active' AND type = 'user' OR deleted = '0'";
		$result = $method->invoke( $exporter, $whereClause );

		// Verify SQL preserves AND/OR structure
		// Should be: `status` = ? AND `type` = ? OR `deleted` = ?
		$this->assertStringContainsString( 'AND', $result['sql'] );
		$this->assertStringContainsString( 'OR', $result['sql'] );

		// Verify the operators appear in the correct order
		$andPos = strpos( $result['sql'], 'AND' );
		$orPos = strpos( $result['sql'], 'OR' );
		$this->assertLessThan( $orPos, $andPos, "AND should appear before OR" );

		// Verify bindings are in correct order
		$this->assertEquals( ['active', 'user', '0'], $result['bindings'] );

		// Verify the exact structure (accounting for identifier quoting)
		$expectedPattern = '/[`"\[]?status[`"\]]?\s*=\s*\?\s*AND\s*[`"\[]?type[`"\]]?\s*=\s*\?\s*OR\s*[`"\[]?deleted[`"\]]?\s*=\s*\?/';
		$this->assertMatchesRegularExpression( $expectedPattern, $result['sql'],
			"SQL should maintain exact AND/OR structure" );
	}

	/**
	 * Test multiple OR conditions followed by AND
	 */
	public function testMultipleOrThenAnd(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseWhereClause' );
		$method->setAccessible( true );

		// Test case: type = 'admin' OR type = 'moderator' AND active = '1'
		$whereClause = "type = 'admin' OR type = 'moderator' AND active = '1'";
		$result = $method->invoke( $exporter, $whereClause );

		// Verify OR appears before AND
		$orPos = strpos( $result['sql'], 'OR' );
		$andPos = strpos( $result['sql'], 'AND' );
		$this->assertLessThan( $andPos, $orPos, "OR should appear before AND" );

		// Verify bindings order
		$this->assertEquals( ['admin', 'moderator', '1'], $result['bindings'] );

		// Verify exact structure preserved
		$expectedPattern = '/[`"\[]?type[`"\]]?\s*=\s*\?\s*OR\s*[`"\[]?type[`"\]]?\s*=\s*\?\s*AND\s*[`"\[]?active[`"\]]?\s*=\s*\?/';
		$this->assertMatchesRegularExpression( $expectedPattern, $result['sql'] );
	}

	/**
	 * Test alternating AND/OR/AND pattern
	 */
	public function testAlternatingAndOrAnd(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseWhereClause' );
		$method->setAccessible( true );

		// Test case: status = 'active' AND role = 'user' OR role = 'guest' AND verified = '1'
		$whereClause = "status = 'active' AND role = 'user' OR role = 'guest' AND verified = '1'";
		$result = $method->invoke( $exporter, $whereClause );

		// Count occurrences of each operator
		$andCount = substr_count( $result['sql'], 'AND' );
		$orCount = substr_count( $result['sql'], 'OR' );

		$this->assertEquals( 2, $andCount, "Should have exactly 2 AND operators" );
		$this->assertEquals( 1, $orCount, "Should have exactly 1 OR operator" );

		// Verify the operators appear in correct sequence
		// Find all operator positions
		preg_match_all( '/\b(AND|OR)\b/', $result['sql'], $matches, PREG_OFFSET_CAPTURE );
		$operators = array_map( function( $match ) {
			return $match[0];
		}, $matches[0] );

		$this->assertEquals( ['AND', 'OR', 'AND'], $operators,
			"Operators should appear in sequence: AND, OR, AND" );

		// Verify bindings
		$this->assertEquals( ['active', 'user', 'guest', '1'], $result['bindings'] );
	}

	/**
	 * Test that all OR conditions don't collapse into single operator
	 */
	public function testAllOrConditionsPreserved(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseWhereClause' );
		$method->setAccessible( true );

		// Test case: Multiple OR conditions
		$whereClause = "type = 'admin' OR type = 'mod' OR type = 'user' OR type = 'guest'";
		$result = $method->invoke( $exporter, $whereClause );

		// Should have exactly 3 OR operators (connecting 4 conditions)
		$orCount = substr_count( $result['sql'], 'OR' );
		$this->assertEquals( 3, $orCount, "Should have exactly 3 OR operators" );

		// Should have no AND operators
		$this->assertStringNotContainsString( 'AND', $result['sql'] );

		// Verify bindings
		$this->assertEquals( ['admin', 'mod', 'user', 'guest'], $result['bindings'] );
	}

	/**
	 * Test that all AND conditions don't get converted to OR
	 */
	public function testAllAndConditionsPreserved(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseWhereClause' );
		$method->setAccessible( true );

		// Test case: Multiple AND conditions
		$whereClause = "active = '1' AND verified = '1' AND enabled = '1' AND visible = '1'";
		$result = $method->invoke( $exporter, $whereClause );

		// Should have exactly 3 AND operators (connecting 4 conditions)
		$andCount = substr_count( $result['sql'], 'AND' );
		$this->assertEquals( 3, $andCount, "Should have exactly 3 AND operators" );

		// Should have no OR operators
		$this->assertStringNotContainsString( 'OR', $result['sql'] );

		// Verify bindings
		$this->assertEquals( ['1', '1', '1', '1'], $result['bindings'] );
	}

	/**
	 * Test complex real-world WHERE clause
	 */
	public function testComplexRealWorldWhereClause(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseWhereClause' );
		$method->setAccessible( true );

		// Complex WHERE clause that might be used in practice
		$whereClause = "deleted = '0' AND status = 'active' OR status = 'pending' AND type = 'user' OR type = 'admin' AND verified = '1'";
		$result = $method->invoke( $exporter, $whereClause );

		// Verify all operators are preserved in order
		preg_match_all( '/\b(AND|OR)\b/', $result['sql'], $matches, PREG_OFFSET_CAPTURE );
		$operators = array_map( function( $match ) {
			return $match[0];
		}, $matches[0] );

		$this->assertEquals( ['AND', 'OR', 'AND', 'OR', 'AND'], $operators,
			"Complex clause should preserve exact operator sequence" );

		// Verify bindings match the conditions in order
		$this->assertEquals( ['0', 'active', 'pending', 'user', 'admin', '1'], $result['bindings'] );
	}

	/**
	 * Test that case variations of AND/OR are normalized
	 */
	public function testCaseInsensitiveOperators(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'parseWhereClause' );
		$method->setAccessible( true );

		// Mixed case operators
		$whereClause = "status = 'active' and type = 'user' Or deleted = '0' AnD visible = '1'";
		$result = $method->invoke( $exporter, $whereClause );

		// All operators should be uppercase in output
		$this->assertStringContainsString( ' AND ', $result['sql'] );
		$this->assertStringContainsString( ' OR ', $result['sql'] );
		$this->assertStringNotContainsString( ' and ', $result['sql'] );
		$this->assertStringNotContainsString( ' Or ', $result['sql'] );
		$this->assertStringNotContainsString( ' AnD ', $result['sql'] );

		// Verify operator sequence is preserved
		preg_match_all( '/\b(AND|OR)\b/', $result['sql'], $matches );
		$this->assertEquals( ['AND', 'OR', 'AND'], $matches[0] );
	}

	// Helper methods

	private function createMockAdapter(): AdapterInterface
	{
		// Create mock PDO for the adapter
		$mockPdo = $this->createMock( \PDO::class );

		// Use getMockBuilder to add the getConnection method
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 10] );
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
}
