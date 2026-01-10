<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporterWithORM;
use Neuron\Mvc\Database\DataImporter;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use PHPUnit\Framework\TestCase;

/**
 * Test that DataExporterWithORM output can be imported by DataImporter
 *
 * Verifies that JSON and YAML export formats include the top-level 'data' key
 * required by DataImporter::importFromJson() and importFromYaml().
 */
class DataExporterImporterRoundtripTest extends TestCase
{
	private $sourceDbPath;
	private $targetDbPath;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temporary database paths
		$this->sourceDbPath = tempnam( sys_get_temp_dir(), 'roundtrip_source_' );
		$this->targetDbPath = tempnam( sys_get_temp_dir(), 'roundtrip_target_' );

		// Reset AdapterFactory
		$this->resetAdapterFactory();
	}

	protected function tearDown(): void
	{
		// Clean up database files
		if( isset( $this->sourceDbPath ) && file_exists( $this->sourceDbPath ) )
		{
			unlink( $this->sourceDbPath );
		}
		if( isset( $this->targetDbPath ) && file_exists( $this->targetDbPath ) )
		{
			unlink( $this->targetDbPath );
		}

		// Reset AdapterFactory
		$this->resetAdapterFactory();

		parent::tearDown();
	}

	/**
	 * Test JSON export has correct 'data' wrapper structure
	 *
	 * Verifies that JSON exported by DataExporterWithORM wraps table data
	 * under a 'data' key, matching DataImporter's expected input format.
	 */
	public function testJsonHasDataWrapper(): void
	{
		// Create source database with test data
		$sourcePdo = new \PDO( 'sqlite:' . $this->sourceDbPath );
		$sourcePdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$sourcePdo->exec( "CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)" );
		$sourcePdo->exec( "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)" );
		$sourcePdo->exec( "INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')" );
		$sourcePdo->exec( "INSERT INTO users (id, name, email) VALUES (2, 'Bob', 'bob@example.com')" );
		$sourcePdo = null;

		// Create target database with schema
		$targetPdo = new \PDO( 'sqlite:' . $this->targetDbPath );
		$targetPdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$targetPdo->exec( "CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)" );
		$targetPdo->exec( "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)" );
		$targetPdo = null;

		// Create exporter
		$sourceConfig = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->sourceDbPath,
					'suffix' => ''
				]
			]
		] );

		$exporter = new DataExporterWithORM(
			$sourceConfig,
			'testing',
			'phinx_log',
			['format' => 'json', 'exclude' => ['phinx_log']]
		);

		// Export to JSON
		$jsonFile = tempnam( sys_get_temp_dir(), 'roundtrip_json_' );
		$exporter->exportToFile( $jsonFile );
		$exporter->disconnect();

		// Read exported JSON
		$json = file_get_contents( $jsonFile );
		unlink( $jsonFile );

		// Verify structure matches DataImporter's expected format
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'JSON should be valid array' );
		$this->assertArrayHasKey( 'data', $decoded, 'JSON must have top-level "data" key for DataImporter' );
		$this->assertArrayHasKey( 'users', $decoded['data'], 'Table data should be nested under "data" key' );
		$this->assertCount( 2, $decoded['data']['users'], 'Should have 2 users' );

		// Verify the structure can be parsed by DataImporter's format expectations
		$this->assertTrue( isset( $decoded['data'] ), 'Structure must have "data" key per DataImporter spec' );

		// Import the exported JSON into target database
		$targetConfig = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->targetDbPath,
					'suffix' => ''
				]
			]
		] );

		$importer = new DataImporter(
			$targetConfig,
			'testing',
			'phinx_log',
			['format' => 'json', 'exclude' => ['phinx_log']]
		);

		$result = $importer->import( $json );
		$this->assertTrue( $result, 'Import should succeed' );

		// Verify data was imported correctly
		$verifyPdo = new \PDO( 'sqlite:' . $this->targetDbPath );
		$users = $verifyPdo->query( 'SELECT * FROM users ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC );
		$this->assertCount( 2, $users, 'Should have imported 2 users' );
		$this->assertEquals( 'Alice', $users[0]['name'], 'First user should be Alice' );
		$this->assertEquals( 'Bob', $users[1]['name'], 'Second user should be Bob' );

		$importer->disconnect();
	}

	/**
	 * Test YAML export has correct 'data' wrapper structure
	 *
	 * Verifies that YAML exported by DataExporterWithORM wraps table data
	 * under a 'data' key, matching DataImporter's expected input format.
	 */
	public function testYamlHasDataWrapper(): void
	{
		// Create source database with test data
		$sourcePdo = new \PDO( 'sqlite:' . $this->sourceDbPath );
		$sourcePdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$sourcePdo->exec( "CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)" );
		$sourcePdo->exec( "CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price REAL)" );
		$sourcePdo->exec( "INSERT INTO products (id, name, price) VALUES (1, 'Widget', 9.99)" );
		$sourcePdo->exec( "INSERT INTO products (id, name, price) VALUES (2, 'Gadget', 19.99)" );
		$sourcePdo = null;

		// Create target database with schema
		$targetPdo = new \PDO( 'sqlite:' . $this->targetDbPath );
		$targetPdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$targetPdo->exec( "CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)" );
		$targetPdo->exec( "CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price REAL)" );
		$targetPdo = null;

		// Create exporter
		$sourceConfig = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->sourceDbPath,
					'suffix' => ''
				]
			]
		] );

		$exporter = new DataExporterWithORM(
			$sourceConfig,
			'testing',
			'phinx_log',
			['format' => 'yaml', 'exclude' => ['phinx_log']]
		);

		// Export to YAML
		$yamlFile = tempnam( sys_get_temp_dir(), 'roundtrip_yaml_' );
		$exporter->exportToFile( $yamlFile );
		$exporter->disconnect();

		// Read exported YAML
		$yaml = file_get_contents( $yamlFile );
		unlink( $yamlFile );

		// Verify structure matches DataImporter's expected format
		$this->assertStringContainsString( 'data:', $yaml, 'YAML must have top-level "data:" key for DataImporter' );

		// Parse back and verify structure
		$parsed = \Symfony\Component\Yaml\Yaml::parse( $yaml );
		$this->assertIsArray( $parsed, 'YAML should parse to array' );
		$this->assertArrayHasKey( 'data', $parsed, 'Parsed YAML must have "data" key' );
		$this->assertArrayHasKey( 'products', $parsed['data'], 'Table data should be nested under "data" key' );
		$this->assertTrue( isset( $parsed['data'] ), 'Structure must have "data" key per DataImporter spec' );

		// Import the exported YAML into target database
		$targetConfig = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->targetDbPath,
					'suffix' => ''
				]
			]
		] );

		$importer = new DataImporter(
			$targetConfig,
			'testing',
			'phinx_log',
			['format' => 'yaml', 'exclude' => ['phinx_log']]
		);

		$result = $importer->import( $yaml );
		$this->assertTrue( $result, 'Import should succeed' );

		// Verify data was imported correctly
		$verifyPdo = new \PDO( 'sqlite:' . $this->targetDbPath );
		$products = $verifyPdo->query( 'SELECT * FROM products ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC );
		$this->assertCount( 2, $products, 'Should have imported 2 products' );
		$this->assertEquals( 'Widget', $products[0]['name'], 'First product should be Widget' );
		$this->assertEquals( 'Gadget', $products[1]['name'], 'Second product should be Gadget' );

		$importer->disconnect();
	}

	/**
	 * Test that exported JSON format structure matches expectations
	 */
	public function testJsonFormatStructure(): void
	{
		// Create source database with test data
		$sourcePdo = new \PDO( 'sqlite:' . $this->sourceDbPath );
		$sourcePdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$sourcePdo->exec( "CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)" );
		$sourcePdo->exec( "CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)" );
		$sourcePdo->exec( "INSERT INTO test (id, value) VALUES (1, 'test')" );
		$sourcePdo = null;

		// Create target database with schema
		$targetPdo = new \PDO( 'sqlite:' . $this->targetDbPath );
		$targetPdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$targetPdo->exec( "CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)" );
		$targetPdo->exec( "CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)" );
		$targetPdo = null;

		// Create exporter
		$sourceConfig = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->sourceDbPath,
					'suffix' => ''
				]
			]
		] );

		$exporter = new DataExporterWithORM(
			$sourceConfig,
			'testing',
			'phinx_log',
			['format' => 'json', 'exclude' => ['phinx_log']]
		);

		// Export to JSON
		$jsonFile = tempnam( sys_get_temp_dir(), 'roundtrip_json_format_' );
		$exporter->exportToFile( $jsonFile );
		$exporter->disconnect();

		// Read exported JSON
		$json = file_get_contents( $jsonFile );
		unlink( $jsonFile );

		// Parse and verify structure
		$data = json_decode( $json, true );

		// Should have 'data' key at top level
		$this->assertArrayHasKey( 'data', $data, 'JSON must have "data" key' );
		$this->assertIsArray( $data['data'], 'data value must be an array' );

		// Tables should be under 'data' key
		if( !empty( $data['data'] ) )
		{
			foreach( $data['data'] as $tableName => $tableData )
			{
				$this->assertIsString( $tableName, 'Table names should be strings' );
				$this->assertIsArray( $tableData, 'Table data should be arrays' );
			}
		}

		// Import into target database to verify roundtrip
		$targetConfig = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->targetDbPath,
					'suffix' => ''
				]
			]
		] );

		$importer = new DataImporter(
			$targetConfig,
			'testing',
			'phinx_log',
			['format' => 'json', 'exclude' => ['phinx_log']]
		);

		$result = $importer->import( $json );
		$this->assertTrue( $result, 'Import should succeed' );

		// Verify data was imported correctly
		$verifyPdo = new \PDO( 'sqlite:' . $this->targetDbPath );
		$testData = $verifyPdo->query( 'SELECT * FROM test ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC );
		$this->assertCount( 1, $testData, 'Should have imported 1 test record' );
		$this->assertEquals( 'test', $testData[0]['value'], 'Test value should match' );

		$importer->disconnect();
	}

	/**
	 * Reset AdapterFactory to ensure clean state
	 */
	private function resetAdapterFactory(): void
	{
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );
	}
}
