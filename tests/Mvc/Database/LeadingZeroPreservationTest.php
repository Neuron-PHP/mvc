<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use Neuron\Mvc\Database\DataImporter;
use Neuron\Mvc\Database\DataExporterWithORM;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that numeric strings with leading zeros are preserved during export/import
 */
class LeadingZeroPreservationTest extends TestCase
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
	 * Test that buildInsertStatements preserves leading zeros
	 */
	public function testBuildInsertStatementsPreservesLeadingZeros(): void
	{
		// Create mock adapter
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		// Create mock PDO for escaping
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		if( method_exists( get_class( $mockAdapter ), 'getConnection' ) )
		{
			$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		}
		else
		{
			$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
				->onlyMethods( get_class_methods( AdapterInterface::class ) )
				->addMethods( ['getConnection'] )
				->getMock();
			$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
			$mockAdapter->method( 'connect' );
			$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		}

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to test private buildInsertStatements method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'buildInsertStatements' );
		$method->setAccessible( true );

		// Test data with various leading zero cases
		$testData = [
			[
				'id' => 1,
				'zip_code' => '00123',      // Leading zeros - should be quoted
				'phone' => '007',           // Leading zeros - should be quoted
				'product_code' => '0001',    // Leading zeros - should be quoted
				'decimal_value' => '0.5',    // Decimal - should NOT be quoted
				'regular_number' => '123',   // Regular number - should NOT be quoted
				'zero' => '0',              // Single zero - should NOT be quoted
				'text' => 'test'            // Regular text - should be quoted
			]
		];

		$sql = $method->invoke( $exporter, 'test_table', $testData );

		// Verify leading zeros are preserved with quotes
		$this->assertStringContainsString( "'00123'", $sql, "Zip code with leading zeros should be quoted" );
		$this->assertStringContainsString( "'007'", $sql, "Phone with leading zeros should be quoted" );
		$this->assertStringContainsString( "'0001'", $sql, "Product code with leading zeros should be quoted" );

		// Verify decimal numbers are not quoted
		$this->assertMatchesRegularExpression( '/,\s*0\.5\s*,/', $sql, "Decimal value should not be quoted" );

		// Verify regular numbers are not quoted
		$this->assertMatchesRegularExpression( '/,\s*123\s*,/', $sql, "Regular number should not be quoted" );

		// Verify single zero is not quoted
		$this->assertMatchesRegularExpression( '/,\s*0\s*,/', $sql, "Single zero should not be quoted" );

		// Verify regular text is quoted
		$this->assertStringContainsString( "'test'", $sql, "Regular text should be quoted" );
	}

	/**
	 * Test that insertBatch preserves leading zeros
	 */
	public function testInsertBatchPreservesLeadingZeros(): void
	{
		$executedSql = [];

		// Create mock adapter
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		// Create mock PDO
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'execute' )->willReturnCallback(
			function( $sql ) use ( &$executedSql ) {
				$executedSql[] = $sql;
				return 1;
			}
		);

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		// Use reflection to test private insertBatch method
		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'insertBatch' );
		$method->setAccessible( true );

		// Test data
		$testData = [
			[
				'account_number' => '00456',
				'reference_code' => '000789',
				'amount' => '100.50',
				'count' => '5'
			]
		];

		$method->invoke( $importer, 'accounts', $testData );

		// Check the executed SQL
		$this->assertNotEmpty( $executedSql );
		$sql = $executedSql[0];

		// Verify leading zeros are preserved
		$this->assertStringContainsString( "'00456'", $sql, "Account number with leading zeros should be quoted" );
		$this->assertStringContainsString( "'000789'", $sql, "Reference code with leading zeros should be quoted" );

		// Regular numbers without leading zeros should not be quoted
		$this->assertMatchesRegularExpression( '/,\s*5\s*\)/', $sql, "Regular count should not be quoted" );
	}

	/**
	 * Test DataExporterWithORM escapeValue method
	 */
	public function testDataExporterWithORMEscapeValue(): void
	{
		// Create mock PDO
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

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
		$exporter = new DataExporterWithORM( $config, 'testing', 'phinx_log' );

		// Use reflection to test private escapeValue method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'escapeValue' );
		$method->setAccessible( true );

		// Test various values
		$this->assertEquals( "'00123'", $method->invoke( $exporter, '00123' ), "Should quote numeric string with leading zeros" );
		$this->assertEquals( "'007'", $method->invoke( $exporter, '007' ), "Should quote '007'" );
		$this->assertEquals( "'0000'", $method->invoke( $exporter, '0000' ), "Should quote multiple zeros" );
		$this->assertEquals( "123", $method->invoke( $exporter, '123' ), "Should not quote regular number" );
		$this->assertEquals( "0.5", $method->invoke( $exporter, '0.5' ), "Should not quote decimal" );
		$this->assertEquals( "0", $method->invoke( $exporter, '0' ), "Should not quote single zero" );
	}

	/**
	 * Test hasLeadingZeros helper method
	 */
	public function testHasLeadingZerosMethod(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		// Use reflection to test private hasLeadingZeros method
		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'hasLeadingZeros' );
		$method->setAccessible( true );

		// Test various inputs
		$this->assertTrue( $method->invoke( $exporter, '007' ), "'007' has leading zeros" );
		$this->assertTrue( $method->invoke( $exporter, '00123' ), "'00123' has leading zeros" );
		$this->assertTrue( $method->invoke( $exporter, '0000' ), "'0000' has leading zeros" );
		$this->assertTrue( $method->invoke( $exporter, '01' ), "'01' has leading zeros" );

		$this->assertFalse( $method->invoke( $exporter, '0' ), "'0' does not have leading zeros" );
		$this->assertFalse( $method->invoke( $exporter, '123' ), "'123' does not have leading zeros" );
		$this->assertFalse( $method->invoke( $exporter, '0.5' ), "'0.5' is a decimal, not leading zeros" );
		$this->assertFalse( $method->invoke( $exporter, '0.123' ), "'0.123' is a decimal" );
		$this->assertFalse( $method->invoke( $exporter, 123 ), "Integer 123 is not a string" );
		$this->assertFalse( $method->invoke( $exporter, 0 ), "Integer 0 is not a string" );
		$this->assertFalse( $method->invoke( $exporter, null ), "null is not a string" );
	}

	/**
	 * Test real-world scenarios
	 */
	public function testRealWorldScenarios(): void
	{
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection'] )
			->getMock();

		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$exporter = new DataExporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $exporter );
		$method = $reflector->getMethod( 'buildInsertStatements' );
		$method->setAccessible( true );

		// Test real-world data that commonly has leading zeros
		$realWorldData = [
			[
				// US ZIP codes
				'zip_code' => '02134',       // Boston area
				'zip_plus4' => '02134-1234',

				// Phone numbers
				'intl_phone' => '0044123456', // International prefix
				'area_code' => '007',         // Some area codes

				// Product codes
				'sku' => '000123',
				'barcode' => '0012345678905',

				// Account numbers
				'bank_account' => '000012345',
				'routing_number' => '021000021',

				// Regular data (should not be quoted)
				'quantity' => '10',
				'price' => '99.99',
				'year' => '2024'
			]
		];

		$sql = $method->invoke( $exporter, 'test_table', $realWorldData );

		// All values with leading zeros should be quoted
		$this->assertStringContainsString( "'02134'", $sql );
		$this->assertStringContainsString( "'02134-1234'", $sql );
		$this->assertStringContainsString( "'0044123456'", $sql );
		$this->assertStringContainsString( "'007'", $sql );
		$this->assertStringContainsString( "'000123'", $sql );
		$this->assertStringContainsString( "'0012345678905'", $sql );
		$this->assertStringContainsString( "'000012345'", $sql );
		$this->assertStringContainsString( "'021000021'", $sql );

		// Regular numbers should not be quoted
		$this->assertMatchesRegularExpression( '/,\s*10\s*,/', $sql );
		$this->assertMatchesRegularExpression( '/,\s*99\.99\s*,/', $sql );
		$this->assertMatchesRegularExpression( '/,\s*2024\s*\)/', $sql );
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
