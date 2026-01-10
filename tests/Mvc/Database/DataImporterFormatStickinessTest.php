<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use PHPUnit\Framework\TestCase;

/**
 * Test that format auto-detection does not cause sticky behavior
 *
 * Verifies that:
 * - importFromFile() restores original format after auto-detection
 * - Multiple calls with different file types work correctly
 * - Format option is not permanently mutated
 */
class DataImporterFormatStickinessTest extends TestCase
{
	private $tempDir;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp directory for test files
		$this->tempDir = sys_get_temp_dir() . '/format_stickiness_test_' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		// Reset AdapterFactory
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );
	}

	protected function tearDown(): void
	{
		// Clean up temporary directory
		if( isset( $this->tempDir ) && is_dir( $this->tempDir ) )
		{
			$this->recursiveRemoveDir( $this->tempDir );
		}

		// Reset AdapterFactory
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Recursively remove directory
	 */
	private function recursiveRemoveDir( string $dir ): void
	{
		if( !is_dir( $dir ) )
		{
			return;
		}

		$files = array_diff( scandir( $dir ), ['.', '..'] );
		foreach( $files as $file )
		{
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->recursiveRemoveDir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * Test that format is restored after auto-detection
	 */
	public function testFormatIsRestoredAfterAutoDetection(): void
	{
		// Create mock adapter
		$mockAdapter = $this->createMock( \Phinx\Db\Adapter\SqliteAdapter::class );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );
		$mockAdapter->method( 'getOptions' )->willReturn( ['name' => ':memory:'] );
		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );

		$mockFactory = $this->createMock( AdapterFactory::class );
		$mockFactory->method( 'getAdapter' )->willReturn( $mockAdapter );

		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, $mockFactory );

		$config = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => ':memory:'
				]
			]
		] );

		// Create importer with format = null (auto-detect)
		$importer = new DataImporter(
			$config,
			'testing',
			'phinx_log',
			['format' => null]  // Auto-detect
		);

		// Create a JSON file
		$jsonFile = $this->tempDir . '/test.json';
		file_put_contents( $jsonFile, json_encode( ['data' => []] ) );

		// Import JSON file - should auto-detect as JSON
		$importer->importFromFile( $jsonFile );

		// Check that format is still null (restored)
		$reflection = new \ReflectionClass( $importer );
		$optionsProperty = $reflection->getProperty( '_Options' );
		$optionsProperty->setAccessible( true );
		$options = $optionsProperty->getValue( $importer );

		$this->assertNull( $options['format'], 'Format should be restored to null after import' );

		$importer->disconnect();
	}

	/**
	 * Test importing multiple files with different formats
	 */
	public function testMultipleImportsWithDifferentFormats(): void
	{
		// Create mock adapter
		$mockAdapter = $this->createMock( \Phinx\Db\Adapter\SqliteAdapter::class );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );
		$mockAdapter->method( 'getOptions' )->willReturn( ['name' => ':memory:'] );
		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );

		$mockFactory = $this->createMock( AdapterFactory::class );
		$mockFactory->method( 'getAdapter' )->willReturn( $mockAdapter );

		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, $mockFactory );

		$config = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => ':memory:'
				]
			]
		] );

		// Create importer with format = null (auto-detect)
		$importer = new DataImporter(
			$config,
			'testing',
			'phinx_log',
			['format' => null]
		);

		// Create files with different formats
		$jsonFile = $this->tempDir . '/test.json';
		file_put_contents( $jsonFile, json_encode( ['data' => []] ) );

		$yamlFile = $this->tempDir . '/test.yaml';
		file_put_contents( $yamlFile, "data: {}" );

		$sqlFile = $this->tempDir . '/test.sql';
		file_put_contents( $sqlFile, "-- Empty SQL file" );

		// Import JSON file
		$result1 = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result1, 'JSON import should succeed' );

		// Import YAML file - should not be affected by previous JSON detection
		$result2 = $importer->importFromFile( $yamlFile );
		$this->assertTrue( $result2, 'YAML import should succeed' );

		// Import SQL file - should not be affected by previous detections
		$result3 = $importer->importFromFile( $sqlFile );
		$this->assertTrue( $result3, 'SQL import should succeed' );

		// Format should still be null (not sticky)
		$reflection = new \ReflectionClass( $importer );
		$optionsProperty = $reflection->getProperty( '_Options' );
		$optionsProperty->setAccessible( true );
		$options = $optionsProperty->getValue( $importer );

		$this->assertNull( $options['format'], 'Format should remain null after multiple imports' );

		$importer->disconnect();
	}

	/**
	 * Test that explicitly set format is also restored
	 */
	public function testExplicitFormatIsRestored(): void
	{
		// Create mock adapter
		$mockAdapter = $this->createMock( \Phinx\Db\Adapter\SqliteAdapter::class );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );
		$mockAdapter->method( 'getOptions' )->willReturn( ['name' => ':memory:'] );
		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );

		$mockFactory = $this->createMock( AdapterFactory::class );
		$mockFactory->method( 'getAdapter' )->willReturn( $mockAdapter );

		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, $mockFactory );

		$config = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => ':memory:'
				]
			]
		] );

		// Create importer with explicit format = json
		$importer = new DataImporter(
			$config,
			'testing',
			'phinx_log',
			['format' => 'json']  // Explicitly set to JSON
		);

		// Create a file that could be detected as YAML
		$yamlFile = $this->tempDir . '/test.yaml';
		file_put_contents( $yamlFile, "data: {}" );

		// Import - should use explicit JSON format, not auto-detect
		// (This will likely fail parsing, but that's ok for this test)
		try
		{
			$importer->importFromFile( $yamlFile );
		}
		catch( \Exception $e )
		{
			// Expected - YAML content can't be parsed as JSON
		}

		// Check that format is still 'json' (restored to original explicit value)
		$reflection = new \ReflectionClass( $importer );
		$optionsProperty = $reflection->getProperty( '_Options' );
		$optionsProperty->setAccessible( true );
		$options = $optionsProperty->getValue( $importer );

		$this->assertEquals( 'json', $options['format'], 'Format should be restored to explicit value' );

		$importer->disconnect();
	}

	/**
	 * Test that format is restored even when import fails
	 */
	public function testFormatIsRestoredOnImportFailure(): void
	{
		// Create mock adapter that will cause import to fail
		$mockAdapter = $this->createMock( \Phinx\Db\Adapter\SqliteAdapter::class );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );
		$mockAdapter->method( 'getOptions' )->willReturn( ['name' => ':memory:'] );
		$mockAdapter->method( 'beginTransaction' )
			->willThrowException( new \RuntimeException( 'Transaction failed' ) );

		$mockFactory = $this->createMock( AdapterFactory::class );
		$mockFactory->method( 'getAdapter' )->willReturn( $mockAdapter );

		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, $mockFactory );

		$config = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => ':memory:'
				]
			]
		] );

		$importer = new DataImporter(
			$config,
			'testing',
			'phinx_log',
			['format' => null]
		);

		// Create a JSON file
		$jsonFile = $this->tempDir . '/test.json';
		file_put_contents( $jsonFile, json_encode( ['data' => []] ) );

		// Import will fail due to transaction error
		try
		{
			$importer->importFromFile( $jsonFile );
		}
		catch( \RuntimeException $e )
		{
			// Expected
		}

		// Format should still be restored to null despite failure
		$reflection = new \ReflectionClass( $importer );
		$optionsProperty = $reflection->getProperty( '_Options' );
		$optionsProperty->setAccessible( true );
		$options = $optionsProperty->getValue( $importer );

		$this->assertNull( $options['format'], 'Format should be restored even when import fails' );

		$importer->disconnect();
	}
}
