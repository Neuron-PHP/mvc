<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Test that the string "NULL" is preserved during import
 * and not incorrectly converted to SQL NULL
 *
 * This test verifies the fix for the data corruption bug where
 * the string "NULL" was being converted to actual NULL values
 */
class NullStringPreservationTest extends TestCase
{
	private $tempDb;
	private $adapter;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp database
		$this->tempDb = tempnam( sys_get_temp_dir(), 'null_test_' ) . '.db';

		// Reset AdapterFactory
		$this->resetAdapterFactory();

		// Create Phinx config
		$config = new Config( [
			'paths' => [
				'migrations' => '/tmp'
			],
			'environments' => [
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->tempDb
				]
			]
		] );

		// Create adapter
		$options = $config->getEnvironment( 'testing' );
		$this->adapter = AdapterFactory::instance()->getAdapter(
			$options['adapter'],
			$options
		);

		$this->adapter->connect();

		// Create test table
		$this->adapter->execute( "
			CREATE TABLE test_data (
				id INTEGER PRIMARY KEY,
				text_field TEXT,
				nullable_field TEXT
			)
		" );
	}

	protected function tearDown(): void
	{
		if( isset( $this->adapter ) )
		{
			$this->adapter->disconnect();
		}

		if( file_exists( $this->tempDb ) )
		{
			unlink( $this->tempDb );
		}

		$this->resetAdapterFactory();

		parent::tearDown();
	}

	/**
	 * Test that the string "NULL" is preserved in JSON import
	 */
	public function testNullStringPreservedInJsonImport(): void
	{
		$config = $this->createConfig();

		// JSON data with string "NULL", actual null, and normal values
		$json = json_encode( [
			'data' => [
				'test_data' => [
					['id' => 1, 'text_field' => 'NULL', 'nullable_field' => 'normal value'],
					['id' => 2, 'text_field' => 'some text', 'nullable_field' => null],
					['id' => 3, 'text_field' => 'NULL', 'nullable_field' => null]
				]
			]
		] );

		// Import from JSON
		$importer = new DataImporter( $config, 'testing', 'phinx_log', [
			'format' => DataImporter::FORMAT_JSON
		] );

		$result = $importer->import( $json );
		$this->assertTrue( $result );
		$importer->disconnect();

		// Verify data after import
		$rows = $this->adapter->fetchAll( "SELECT * FROM test_data ORDER BY id" );

		$this->assertCount( 3, $rows );

		// Row 1: string "NULL" should be preserved as string, not converted to NULL
		$this->assertEquals( 1, $rows[0]['id'] );
		$this->assertEquals( 'NULL', $rows[0]['text_field'], 'String "NULL" should not be converted to SQL NULL' );
		$this->assertEquals( 'normal value', $rows[0]['nullable_field'] );

		// Row 2: actual NULL should remain NULL
		$this->assertEquals( 2, $rows[1]['id'] );
		$this->assertEquals( 'some text', $rows[1]['text_field'] );
		$this->assertNull( $rows[1]['nullable_field'] );

		// Row 3: string "NULL" and actual NULL should be distinct
		$this->assertEquals( 3, $rows[2]['id'] );
		$this->assertEquals( 'NULL', $rows[2]['text_field'], 'String "NULL" should not be converted to SQL NULL' );
		$this->assertNull( $rows[2]['nullable_field'] );
	}

	/**
	 * Test that the string "NULL" is preserved in YAML import
	 */
	public function testNullStringPreservedInYamlImport(): void
	{
		$config = $this->createConfig();

		// YAML data with string "NULL"
		$yaml = "
data:
  test_data:
    - id: 1
      text_field: 'NULL'
      nullable_field: 'value'
    - id: 2
      text_field: 'NULL'
      nullable_field: null
";

		// Import from YAML
		$importer = new DataImporter( $config, 'testing', 'phinx_log', [
			'format' => DataImporter::FORMAT_YAML
		] );

		$result = $importer->import( $yaml );
		$this->assertTrue( $result );
		$importer->disconnect();

		// Verify string "NULL" preserved
		$rows = $this->adapter->fetchAll( "SELECT * FROM test_data ORDER BY id" );
		$this->assertCount( 2, $rows );
		$this->assertEquals( 'NULL', $rows[0]['text_field'], 'String "NULL" should not be converted to SQL NULL' );
		$this->assertEquals( 'NULL', $rows[1]['text_field'], 'String "NULL" should not be converted to SQL NULL' );
		$this->assertNull( $rows[1]['nullable_field'] );
	}

	// Helper methods

	private function createConfig(): Config
	{
		return new Config( [
			'paths' => [
				'migrations' => '/tmp'
			],
			'environments' => [
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->tempDb
				]
			]
		] );
	}

	private function resetAdapterFactory(): void
	{
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		if( !$factoryClass->hasProperty( 'instance' ) )
		{
			throw new \RuntimeException( "AdapterFactory::instance property not found" );
		}
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );
	}
}
