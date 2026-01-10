<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use PHPUnit\Framework\TestCase;

/**
 * Test that DataImporter properly validates malformed data structures
 *
 * Verifies that import methods throw helpful validation errors instead of
 * PHP TypeErrors when encountering non-array data structures.
 */
class DataImporterMalformedDataTest extends TestCase
{
	private string $_dbPath;
	private Config $_config;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temporary database path
		$this->_dbPath = tempnam( sys_get_temp_dir(), 'malformed_test_' ) . '.db';

		// Create Phinx config
		$this->_config = new Config( [
			'paths' => [
				'migrations' => __DIR__
			],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->_dbPath
				]
			]
		] );

		// Reset AdapterFactory
		$this->resetAdapterFactory();

		// Create database with schema
		$pdo = new \PDO( 'sqlite:' . $this->_dbPath );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$pdo->exec( "CREATE TABLE phinx_log (version INTEGER PRIMARY KEY)" );
		$pdo->exec( "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)" );
		$pdo = null;
	}

	protected function tearDown(): void
	{
		if( isset( $this->_dbPath ) && file_exists( $this->_dbPath ) )
		{
			unlink( $this->_dbPath );
		}

		$this->resetAdapterFactory();

		parent::tearDown();
	}

	/**
	 * Reset AdapterFactory singleton to ensure clean state between tests
	 */
	private function resetAdapterFactory(): void
	{
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );
	}

	/**
	 * Test that JSON with non-array "data" value throws helpful error
	 */
	public function testJsonWithNonArrayDataThrowsError(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid JSON structure: "data" must be an array, got string' );

		$importer = new DataImporter(
			$this->_config,
			'testing',
			'phinx_log',
			['format' => 'json']
		);

		// Malformed JSON: "data" is a string instead of array
		$malformedJson = json_encode( [
			'data' => 'invalid'
		] );

		$importer->import( $malformedJson );
	}

	/**
	 * Test that YAML with non-array "data" value throws helpful error
	 */
	public function testYamlWithNonArrayDataThrowsError(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid YAML structure: "data" must be an array, got string' );

		$importer = new DataImporter(
			$this->_config,
			'testing',
			'phinx_log',
			['format' => 'yaml']
		);

		// Malformed YAML: "data" is a string instead of array
		$malformedYaml = "data: invalid";

		$importer->import( $malformedYaml );
	}

	/**
	 * Test that JSON with non-array table data throws helpful error (stop_on_error=true)
	 */
	public function testJsonWithNonArrayTableDataThrowsError(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "Invalid data for table 'users': expected array, got string" );

		$importer = new DataImporter(
			$this->_config,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'stop_on_error' => true  // Throw exception instead of logging error
			]
		);

		// Malformed JSON: table data is a string instead of array
		$malformedJson = json_encode( [
			'data' => [
				'users' => 'not-an-array'
			]
		] );

		$importer->import( $malformedJson );
	}

	/**
	 * Test that JSON with non-array table data logs error (stop_on_error=false)
	 */
	public function testJsonWithNonArrayTableDataLogsError(): void
	{
		$importer = new DataImporter(
			$this->_config,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'stop_on_error' => false  // Log error instead of throwing exception
			]
		);

		// Malformed JSON: table data is a string instead of array
		$malformedJson = json_encode( [
			'data' => [
				'users' => 'not-an-array'
			]
		] );

		$result = $importer->import( $malformedJson );

		// Import should fail
		$this->assertFalse( $result, "Import should fail with malformed data" );

		// Should have recorded an error
		$errors = $importer->getErrors();
		$this->assertNotEmpty( $errors, "Should have at least one error" );
		$this->assertStringContainsString( "Invalid data for table 'users'", $errors[0] );
		$this->assertStringContainsString( "expected array, got string", $errors[0] );
	}

	/**
	 * Test that JSON with non-array rows throws helpful error (stop_on_error=true)
	 */
	public function testJsonWithNonArrayRowsThrowsError(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "Invalid rows data for table 'users': expected array, got string" );

		$importer = new DataImporter(
			$this->_config,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'stop_on_error' => true
			]
		);

		// Malformed JSON: rows is a string instead of array
		$malformedJson = json_encode( [
			'data' => [
				'users' => [
					'rows' => 'not-an-array'
				]
			]
		] );

		$importer->import( $malformedJson );
	}

	/**
	 * Test that JSON with non-array rows logs error (stop_on_error=false)
	 */
	public function testJsonWithNonArrayRowsLogsError(): void
	{
		$importer = new DataImporter(
			$this->_config,
			'testing',
			'phinx_log',
			[
				'format' => 'json',
				'stop_on_error' => false
			]
		);

		// Malformed JSON: rows is a string instead of array
		$malformedJson = json_encode( [
			'data' => [
				'users' => [
					'rows' => 'not-an-array'
				]
			]
		] );

		$result = $importer->import( $malformedJson );

		// Import should fail
		$this->assertFalse( $result, "Import should fail with malformed rows" );

		// Should have recorded an error
		$errors = $importer->getErrors();
		$this->assertNotEmpty( $errors, "Should have at least one error" );
		$this->assertStringContainsString( "Invalid rows data for table 'users'", $errors[0] );
		$this->assertStringContainsString( "expected array, got string", $errors[0] );
	}

}
