<?php

namespace Tests\Mvc\Database;

use PHPUnit\Framework\TestCase;
use Neuron\Mvc\Database\DataExporter;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Test WHERE clause parsing to verify mixed AND/OR operators are preserved
 */
class DataExporterWhereClauseTest extends TestCase
{
	private static $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

		// Capture original AdapterFactory
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		self::$originalFactory = $instanceProperty->getValue();
	}

	protected function tearDown(): void
	{
		// Restore original AdapterFactory
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, self::$originalFactory );

		parent::tearDown();
	}

	/**
	 * Test that mixed AND/OR operators are preserved correctly
	 */
	public function testMixedAndOrOperatorsPreserved(): void
	{
		// Create test exporter
		$exporter = $this->createTestExporter();

		// Test various WHERE clauses with mixed operators
		$testCases = [
			[
				'input' => "status = 'active' AND type = 'user' OR category = 'admin'",
				'expectedSql' => "`status` = ? AND `type` = ? OR `category` = ?",
				'expectedBindings' => ['active', 'user', 'admin'],
				'description' => 'Simple mixed AND/OR'
			],
			[
				'input' => "age > 18 OR status = 'verified' AND country = 'US'",
				'expectedSql' => "`age` > ? OR `status` = ? AND `country` = ?",
				'expectedBindings' => ['18', 'verified', 'US'],
				'description' => 'OR before AND'
			],
			[
				'input' => "type = 'premium' AND active = 1 OR type = 'trial' AND days_left > 0",
				'expectedSql' => "`type` = ? AND `active` = ? OR `type` = ? AND `days_left` > ?",
				'expectedBindings' => ['premium', '1', 'trial', '0'],
				'description' => 'Multiple AND/OR combinations'
			],
			[
				'input' => "a = 1 AND b = 2 AND c = 3 OR d = 4 AND e = 5",
				'expectedSql' => "`a` = ? AND `b` = ? AND `c` = ? OR `d` = ? AND `e` = ?",
				'expectedBindings' => ['1', '2', '3', '4', '5'],
				'description' => 'Multiple ANDs with OR'
			],
		];

		foreach( $testCases as $testCase )
		{
			$result = $exporter->testParseWhereClause( $testCase['input'] );

			$this->assertEquals(
				$testCase['expectedSql'],
				$result['sql'],
				"Failed for: {$testCase['description']}"
			);

			$this->assertEquals(
				$testCase['expectedBindings'],
				$result['bindings'],
				"Bindings failed for: {$testCase['description']}"
			);
		}
	}

	/**
	 * Test that only AND operators work correctly
	 */
	public function testOnlyAndOperators(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause(
			"status = 'active' AND type = 'user' AND verified = 1"
		);

		$this->assertEquals(
			"`status` = ? AND `type` = ? AND `verified` = ?",
			$result['sql']
		);

		$this->assertEquals(
			['active', 'user', '1'],
			$result['bindings']
		);
	}

	/**
	 * Test that only OR operators work correctly
	 */
	public function testOnlyOrOperators(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause(
			"type = 'admin' OR type = 'moderator' OR type = 'superuser'"
		);

		$this->assertEquals(
			"`type` = ? OR `type` = ? OR `type` = ?",
			$result['sql']
		);

		$this->assertEquals(
			['admin', 'moderator', 'superuser'],
			$result['bindings']
		);
	}

	/**
	 * Test case insensitive operator handling
	 */
	public function testCaseInsensitiveOperators(): void
	{
		$exporter = $this->createTestExporter();

		$testCases = [
			"status = 'active' and type = 'user' or category = 'admin'",
			"status = 'active' And type = 'user' Or category = 'admin'",
			"status = 'active' AND type = 'user' OR category = 'admin'",
		];

		$expectedSql = "`status` = ? AND `type` = ? OR `category` = ?";
		$expectedBindings = ['active', 'user', 'admin'];

		foreach( $testCases as $input )
		{
			$result = $exporter->testParseWhereClause( $input );

			$this->assertEquals(
				$expectedSql,
				$result['sql'],
				"Case insensitive failed for: {$input}"
			);

			$this->assertEquals(
				$expectedBindings,
				$result['bindings']
			);
		}
	}

	/**
	 * Test complex real-world WHERE clauses
	 */
	public function testComplexRealWorldClauses(): void
	{
		$exporter = $this->createTestExporter();

		// E-commerce query
		$result = $exporter->testParseWhereClause(
			"status = 'published' AND stock > 0 AND category = 'electronics' OR featured = 1 AND discount > 10"
		);

		$this->assertEquals(
			"`status` = ? AND `stock` > ? AND `category` = ? OR `featured` = ? AND `discount` > ?",
			$result['sql']
		);

		$this->assertEquals(
			['published', '0', 'electronics', '1', '10'],
			$result['bindings']
		);

		// User permissions query
		$result = $exporter->testParseWhereClause(
			"role = 'admin' OR role = 'moderator' AND active = 1 OR role = 'user' AND verified = 1 AND subscription = 'premium'"
		);

		$this->assertEquals(
			"`role` = ? OR `role` = ? AND `active` = ? OR `role` = ? AND `verified` = ? AND `subscription` = ?",
			$result['sql']
		);

		$this->assertEquals(
			['admin', 'moderator', '1', 'user', '1', 'premium'],
			$result['bindings']
		);
	}

	/**
	 * Test different comparison operators with mixed AND/OR
	 */
	public function testDifferentComparisonOperators(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause(
			"age >= 18 AND status != 'banned' OR vip = 1 AND credits > 100"
		);

		$this->assertEquals(
			"`age` >= ? AND `status` != ? OR `vip` = ? AND `credits` > ?",
			$result['sql']
		);

		$this->assertEquals(
			['18', 'banned', '1', '100'],
			$result['bindings']
		);
	}

	/**
	 * Test LIKE operator with mixed AND/OR
	 */
	public function testLikeOperatorWithMixedOperators(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause(
			"name LIKE '%john%' AND active = 1 OR email LIKE '%@admin.com' AND role = 'admin'"
		);

		$this->assertEquals(
			"`name` LIKE ? AND `active` = ? OR `email` LIKE ? AND `role` = ?",
			$result['sql']
		);

		$this->assertEquals(
			['%john%', '1', '%@admin.com', 'admin'],
			$result['bindings']
		);
	}

	/**
	 * Test that parentheses are not supported
	 */
	public function testParenthesesNotSupported(): void
	{
		$exporter = $this->createTestExporter();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Parentheses are not supported in WHERE conditions' );

		// Parentheses are not supported in simple parser
		$exporter->testParseWhereClause(
			"(status = 'active' AND type = 'user') OR category = 'admin'"
		);
	}

	/**
	 * Test single condition (no operators)
	 */
	public function testSingleCondition(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause( "status = 'active'" );

		$this->assertEquals( "`status` = ?", $result['sql'] );
		$this->assertEquals( ['active'], $result['bindings'] );
	}

	/**
	 * Test empty WHERE clause
	 */
	public function testEmptyWhereClause(): void
	{
		$exporter = $this->createTestExporter();

		$this->expectException( \InvalidArgumentException::class );

		$exporter->testParseWhereClause( '' );
	}

	// Helper method to create test exporter with access to private method
	private function createTestExporter(): object
	{
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getTables'] )
			->getMock();

		$mockConfig = new Config( [
			'paths' => ['migrations' => '/test'],
			'environments' => [
				'testing' => [
					'adapter' => 'mysql',
					'host' => 'localhost',
					'name' => 'test_db',
					'user' => 'root',
					'pass' => '',
				]
			]
		] );

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'getTables' )->willReturn( [] );

		$this->mockAdapterFactory( $mockAdapter );

		// Create DataExporter and return wrapper to access private method
		$exporter = new DataExporter( $mockConfig, 'testing', 'migrations' );

		// Return wrapper object that uses reflection to access private method
		return new class( $exporter ) {
			private $exporter;
			private $method;

			public function __construct( $exporter )
			{
				$this->exporter = $exporter;
				$reflection = new \ReflectionClass( DataExporter::class );
				$this->method = $reflection->getMethod( 'parseSimpleWhereClause' );
				$this->method->setAccessible( true );
			}

			public function testParseWhereClause( string $whereClause ): array
			{
				return $this->method->invoke( $this->exporter, $whereClause );
			}
		};
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