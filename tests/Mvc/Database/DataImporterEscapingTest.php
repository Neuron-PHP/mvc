<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * Test DataImporter's escapeString method uses adapter's native quoting
 */
class DataImporterEscapingTest extends TestCase
{
	private $tempDir;
	private static $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp directory for test files
		$this->tempDir = sys_get_temp_dir() . '/escaping_test_' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		// Capture original AdapterFactory
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		self::$originalFactory = $instanceProperty->getValue();
	}

	protected function tearDown(): void
	{
		// Clean up temp directory
		if( is_dir( $this->tempDir ) )
		{
			$this->recursiveRemoveDir( $this->tempDir );
		}

		// Restore original AdapterFactory
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, self::$originalFactory );

		parent::tearDown();
	}

	/**
	 * Test that escapeString uses PDO quote when available
	 */
	public function testEscapeStringUsesPdoQuote(): void
	{
		// Create a mock PDO that we can verify quote() is called
		$mockPdo = $this->createMock( \PDO::class );

		// Test various strings that need escaping
		$testCases = [
			"O'Brien" => "'O''Brien'",  // PDO typically doubles quotes
			'Say "Hello"' => "'Say \"Hello\"'",  // Different quote type
			"Line\nBreak" => "'Line\\nBreak'",  // Newline
			"Tab\there" => "'Tab\\there'",  // Tab
		];

		foreach( $testCases as $input => $pdoQuoted )
		{
			$mockPdo->expects( $this->once() )
				->method( 'quote' )
				->with( $input )
				->willReturn( $pdoQuoted );

			// Create mock adapter that returns our mock PDO
			$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
				->onlyMethods( get_class_methods( AdapterInterface::class ) )
				->addMethods( ['getConnection'] )
				->getMock();
			$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
			$mockAdapter->method( 'connect' );
			$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
			$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );

			$this->mockAdapterFactory( $mockAdapter );

			// Create DataImporter and use reflection to test private escapeString
			$config = $this->createMockConfig();
			$importer = new DataImporter( $config, 'testing', 'phinx_log' );

			$reflector = new \ReflectionClass( $importer );
			$method = $reflector->getMethod( 'escapeString' );
			$method->setAccessible( true );

			// Call escapeString and verify it returns the unquoted escaped value
			$result = $method->invoke( $importer, $input );

			// Result should be the quoted value with surrounding quotes stripped
			$expected = substr( $pdoQuoted, 1, -1 );
			$this->assertEquals( $expected, $result, "Failed for input: {$input}" );

			// Reset mock for next iteration
			$mockPdo = $this->createMock( \PDO::class );
		}
	}

	/**
	 * Test fallback to manual escaping when PDO is not available
	 */
	public function testFallbackToManualEscaping(): void
	{
		// Create adapter without getConnection method
		$mockAdapter = $this->createMock( \Phinx\Db\Adapter\AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'escapeString' );
		$method->setAccessible( true );

		// Test manual escaping fallback
		$this->assertEquals( "O''Brien", $method->invoke( $importer, "O'Brien" ) );
		$this->assertEquals( 'Say \\"Hello\\"', $method->invoke( $importer, 'Say "Hello"' ) );
		$this->assertEquals( "Line\\nBreak", $method->invoke( $importer, "Line\nBreak" ) );
		$this->assertEquals( "Tab\\there", $method->invoke( $importer, "Tab\there" ) );
	}

	/**
	 * Test escaping in actual import scenario
	 */
	public function testEscapingInImport(): void
	{
		// Create a mock PDO for escaping
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )
			->willReturnCallback( function( $str ) {
				// Simulate PDO quote behavior
				return "'" . str_replace( "'", "''", $str ) . "'";
			} );

		// Create adapter with PDO support
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['hasTransaction', 'getConnection'] )
			->getMock();
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );

		$this->mockAdapterFactory( $mockAdapter );

		// Create JSON with strings that need escaping
		$jsonFile = $this->tempDir . '/test.json';
		$data = [
			'data' => [
				'users' => [
					['id' => 1, 'name' => "O'Brien", 'note' => "John's data"],
					['id' => 2, 'name' => 'Normal', 'note' => 'No escaping needed'],
				]
			]
		];
		file_put_contents( $jsonFile, json_encode( $data ) );

		$config = $this->createMockConfig();
		$importer = new DataImporter(
			$config,
			'testing',
			'phinx_log',
			['format' => 'json']
		);

		// Import should work without SQL injection issues
		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result );
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

	private function recursiveRemoveDir( $dir ): void
	{
		if( !is_dir( $dir ) ) return;

		$objects = scandir( $dir );
		foreach( $objects as $object )
		{
			if( $object != "." && $object != ".." )
			{
				if( is_dir( $dir . "/" . $object ) )
				{
					$this->recursiveRemoveDir( $dir . "/" . $object );
				}
				else
				{
					unlink( $dir . "/" . $object );
				}
			}
		}
		rmdir( $dir );
	}
}