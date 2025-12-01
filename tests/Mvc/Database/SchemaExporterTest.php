<?php

namespace Mvc\Database;

use Neuron\Mvc\Database\SchemaExporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use org\bovigo\vfs\vfsStream;

/**
 * Unit tests for SchemaExporter
 */
class SchemaExporterTest extends TestCase
{
	private $root;

	protected function setUp(): void
	{
		parent::setUp();

		// Set up virtual file system
		$this->root = vfsStream::setup( 'test' );
	}

	/**
	 * Test that exporter can be instantiated with valid config
	 */
	public function testInstantiationWithValidConfig()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$exporter = new SchemaExporter( $config, 'testing' );

		$this->assertInstanceOf( SchemaExporter::class, $exporter );
	}

	/**
	 * Test export to file creates directory if needed
	 */
	public function testExportToFileCreatesDirectory()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$exporter = new SchemaExporter( $config, 'testing' );

		$outputPath = vfsStream::url( 'test/db/schema.yaml' );
		$result = $exporter->exportToFile( $outputPath );

		$this->assertTrue( $result );
		$this->assertTrue( $this->root->hasChild( 'db/schema.yaml' ) );
	}

	/**
	 * Test export generates valid YAML
	 */
	public function testExportGeneratesValidYaml()
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		$config = $this->createMockConfig();
		$exporter = new SchemaExporter( $config, 'testing' );

		$yaml = $exporter->export();

		$this->assertNotEmpty( $yaml );
		$this->assertStringContainsString( 'version:', $yaml );
		$this->assertStringContainsString( 'tables:', $yaml );
	}

	/**
	 * Create a mock Phinx config for testing
	 *
	 * @return Config
	 */
	private function createMockConfig(): Config
	{
		return new Config( [
			'paths' => [
				'migrations' => vfsStream::url( 'test/migrations' )
			],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => vfsStream::url( 'test/test.db' )
				]
			]
		] );
	}
}
