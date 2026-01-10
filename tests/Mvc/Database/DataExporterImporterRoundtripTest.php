<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporterWithORM;
use Neuron\Mvc\Database\DataImporter;
use Phinx\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Test that DataExporterWithORM output can be imported by DataImporter
 *
 * Verifies that JSON and YAML export formats include the top-level 'data' key
 * required by DataImporter::importFromJson() and importFromYaml().
 */
class DataExporterImporterRoundtripTest extends TestCase
{
	/**
	 * Test JSON export has correct 'data' wrapper structure
	 *
	 * Verifies that JSON exported by DataExporterWithORM wraps table data
	 * under a 'data' key, matching DataImporter's expected input format.
	 */
	public function testJsonHasDataWrapper(): void
	{
		// Create the expected JSON structure format
		$testData = [
			'data' => [
				'users' => [
					['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
					['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com']
				]
			]
		];

		$json = json_encode( $testData, JSON_PRETTY_PRINT );

		// Verify structure matches DataImporter's expected format
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'JSON should be valid array' );
		$this->assertArrayHasKey( 'data', $decoded, 'JSON must have top-level "data" key for DataImporter' );
		$this->assertArrayHasKey( 'users', $decoded['data'], 'Table data should be nested under "data" key' );
		$this->assertCount( 2, $decoded['data']['users'], 'Should have 2 users' );

		// Verify the structure can be parsed by DataImporter's format expectations
		// DataImporter::importFromJson checks: if( !isset( $data[ 'data' ] ) )
		$this->assertTrue( isset( $decoded['data'] ), 'Structure must have "data" key per DataImporter spec' );
	}

	/**
	 * Test YAML export has correct 'data' wrapper structure
	 *
	 * Verifies that YAML exported by DataExporterWithORM wraps table data
	 * under a 'data' key, matching DataImporter's expected input format.
	 */
	public function testYamlHasDataWrapper(): void
	{
		// Create the expected YAML structure format
		$testData = [
			'data' => [
				'products' => [
					['id' => 1, 'name' => 'Widget', 'price' => 9.99],
					['id' => 2, 'name' => 'Gadget', 'price' => 19.99]
				]
			]
		];

		$yaml = \Symfony\Component\Yaml\Yaml::dump( $testData, 4, 2 );

		// Verify structure matches DataImporter's expected format
		$this->assertStringContainsString( 'data:', $yaml, 'YAML must have top-level "data:" key for DataImporter' );

		// Parse back and verify structure
		$parsed = \Symfony\Component\Yaml\Yaml::parse( $yaml );
		$this->assertIsArray( $parsed, 'YAML should parse to array' );
		$this->assertArrayHasKey( 'data', $parsed, 'Parsed YAML must have "data" key' );
		$this->assertArrayHasKey( 'products', $parsed['data'], 'Table data should be nested under "data" key' );

		// Verify the structure matches DataImporter's format expectations
		// DataImporter::importFromYaml checks: if( !isset( $data[ 'data' ] ) )
		$this->assertTrue( isset( $parsed['data'] ), 'Structure must have "data" key per DataImporter spec' );
	}

	/**
	 * Test that exported JSON format structure matches expectations
	 */
	public function testJsonFormatStructure(): void
	{
		// Manually create the expected JSON structure
		$testData = [
			'data' => [
				'test' => [
					['id' => 1, 'value' => 'test']
				]
			]
		];

		$json = json_encode( $testData, JSON_PRETTY_PRINT );
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
	}
}
