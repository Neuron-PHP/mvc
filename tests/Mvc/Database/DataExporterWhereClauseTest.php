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
	 * Test that parentheses are only supported for IN/NOT IN operators
	 */
	public function testParenthesesOnlyForInOperators(): void
	{
		$exporter = $this->createTestExporter();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Parentheses are only supported for IN/NOT IN operators' );

		// Parentheses for grouping are not supported in simple parser
		$exporter->testParseWhereClause(
			"(status = 'active' AND type = 'user') OR category = 'admin'"
		);
	}

	/**
	 * Test IN operator with numeric values
	 */
	public function testInOperatorWithNumericValues(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause( "id IN (1, 2, 3)" );

		$this->assertEquals( "`id` IN (?, ?, ?)", $result['sql'] );
		$this->assertEquals( ['1', '2', '3'], $result['bindings'] );
	}

	/**
	 * Test IN operator with quoted string values
	 */
	public function testInOperatorWithStringValues(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause( "status IN ('active', 'pending', 'approved')" );

		$this->assertEquals( "`status` IN (?, ?, ?)", $result['sql'] );
		$this->assertEquals( ['active', 'pending', 'approved'], $result['bindings'] );
	}

	/**
	 * Test NOT IN operator
	 */
	public function testNotInOperator(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause( "status NOT IN ('deleted', 'archived')" );

		$this->assertEquals( "`status` NOT IN (?, ?)", $result['sql'] );
		$this->assertEquals( ['deleted', 'archived'], $result['bindings'] );
	}

	/**
	 * Test IN operator with mixed quotes
	 */
	public function testInOperatorWithMixedQuotes(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause( 'type IN ("admin", \'user\', "guest")' );

		$this->assertEquals( "`type` IN (?, ?, ?)", $result['sql'] );
		$this->assertEquals( ['admin', 'user', 'guest'], $result['bindings'] );
	}

	/**
	 * Test IN operator with SQL-escaped quotes
	 */
	public function testInOperatorWithEscapedQuotes(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause( "name IN ('O''Brien', 'O''Connor', 'Smith')" );

		$this->assertEquals( "`name` IN (?, ?, ?)", $result['sql'] );
		$this->assertEquals( ["O'Brien", "O'Connor", 'Smith'], $result['bindings'] );
	}

	/**
	 * Test IN operator with whitespace variations
	 */
	public function testInOperatorWithWhitespace(): void
	{
		$exporter = $this->createTestExporter();

		$testCases = [
			"id IN (1,2,3)",           // No spaces
			"id IN ( 1 , 2 , 3 )",     // Extra spaces
			"id IN (1, 2, 3)",         // Normal spacing
			"id IN  (  1  ,  2  ,  3  )", // Lots of spaces
		];

		foreach( $testCases as $input )
		{
			$result = $exporter->testParseWhereClause( $input );

			$this->assertEquals( "`id` IN (?, ?, ?)", $result['sql'], "Failed for: {$input}" );
			$this->assertEquals( ['1', '2', '3'], $result['bindings'], "Bindings failed for: {$input}" );
		}
	}

	/**
	 * Test IN operator combined with AND/OR
	 */
	public function testInOperatorWithAndOr(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause(
			"status IN ('active', 'pending') AND type = 'user' OR priority > 5"
		);

		$this->assertEquals(
			"`status` IN (?, ?) AND `type` = ? OR `priority` > ?",
			$result['sql']
		);

		$this->assertEquals(
			['active', 'pending', 'user', '5'],
			$result['bindings']
		);
	}

	/**
	 * Test multiple IN operators in same clause
	 */
	public function testMultipleInOperators(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause(
			"status IN ('active', 'pending') AND role IN ('admin', 'moderator')"
		);

		$this->assertEquals(
			"`status` IN (?, ?) AND `role` IN (?, ?)",
			$result['sql']
		);

		$this->assertEquals(
			['active', 'pending', 'admin', 'moderator'],
			$result['bindings']
		);
	}

	/**
	 * Test IN with single value
	 */
	public function testInOperatorWithSingleValue(): void
	{
		$exporter = $this->createTestExporter();

		$result = $exporter->testParseWhereClause( "id IN (42)" );

		$this->assertEquals( "`id` IN (?)", $result['sql'] );
		$this->assertEquals( ['42'], $result['bindings'] );
	}

	/**
	 * Test empty IN list throws exception
	 */
	public function testInOperatorWithEmptyListThrows(): void
	{
		$exporter = $this->createTestExporter();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Empty IN list' );

		$exporter->testParseWhereClause( "id IN ()" );
	}

	/**
	 * Test empty string values in IN lists
	 * Ensures empty quoted strings ('', "") are properly handled and not silently dropped
	 */
	public function testInListWithEmptyStrings(): void
	{
		$exporter = $this->createTestExporter();

		// Test single empty string
		$result = $exporter->testParseWhereClause( "status IN ('')" );
		$this->assertEquals( "`status` IN (?)", $result['sql'] );
		$this->assertEquals( [''], $result['bindings'] );

		// Test double-quoted empty string
		$result = $exporter->testParseWhereClause( 'status IN ("")' );
		$this->assertEquals( "`status` IN (?)", $result['sql'] );
		$this->assertEquals( [''], $result['bindings'] );

		// Test empty string mixed with non-empty values
		$result = $exporter->testParseWhereClause( "status IN ('active', '', 'pending')" );
		$this->assertEquals( "`status` IN (?, ?, ?)", $result['sql'] );
		$this->assertEquals( ['active', '', 'pending'], $result['bindings'] );

		// Test multiple empty strings
		$result = $exporter->testParseWhereClause( "status IN ('', '', 'active')" );
		$this->assertEquals( "`status` IN (?, ?, ?)", $result['sql'] );
		$this->assertEquals( ['', '', 'active'], $result['bindings'] );

		// Test NOT IN with empty strings
		$result = $exporter->testParseWhereClause( "status NOT IN ('', 'deleted')" );
		$this->assertEquals( "`status` NOT IN (?, ?)", $result['sql'] );
		$this->assertEquals( ['', 'deleted'], $result['bindings'] );

		// Test empty string with spaces (should still be empty)
		$result = $exporter->testParseWhereClause( "status IN (  ''  ,  'active'  )" );
		$this->assertEquals( "`status` IN (?, ?)", $result['sql'] );
		$this->assertEquals( ['', 'active'], $result['bindings'] );
	}

	/**
	 * Test case insensitive IN operator
	 */
	public function testInOperatorCaseInsensitive(): void
	{
		$exporter = $this->createTestExporter();

		$testCases = [
			"id in (1, 2, 3)",
			"id In (1, 2, 3)",
			"id IN (1, 2, 3)",
		];

		foreach( $testCases as $input )
		{
			$result = $exporter->testParseWhereClause( $input );

			$this->assertEquals( "`id` IN (?, ?, ?)", $result['sql'], "Failed for: {$input}" );
			$this->assertEquals( ['1', '2', '3'], $result['bindings'] );
		}
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
