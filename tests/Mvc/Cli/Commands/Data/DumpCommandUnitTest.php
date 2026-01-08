<?php

namespace Tests\Mvc\Cli\Commands\Data;

use Neuron\Mvc\Cli\Commands\Data\DumpCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DumpCommand
 */
class DumpCommandUnitTest extends TestCase
{
	private DumpCommand $command;

	protected function setUp(): void
	{
		$this->command = new DumpCommand();
	}

	/**
	 * Test command name is correct
	 */
	public function testCommandName(): void
	{
		$this->assertEquals( 'db:data:dump', $this->command->getName() );
	}

	/**
	 * Test command description is set
	 */
	public function testCommandDescription(): void
	{
		$this->assertEquals(
			'Export database data in various formats (SQL, JSON, CSV, YAML)',
			$this->command->getDescription()
		);
	}

	/**
	 * Test command configuration sets all required options
	 */
	public function testCommandConfiguration(): void
	{
		$this->command->configure();
		$options = $this->command->getOptions();

		// Output options
		$this->assertArrayHasKey( 'output', $options );
		$this->assertArrayHasKey( 'format', $options );

		// Table selection options
		$this->assertArrayHasKey( 'tables', $options );
		$this->assertArrayHasKey( 'exclude', $options );

		// Data filtering options
		$this->assertArrayHasKey( 'limit', $options );
		$this->assertArrayHasKey( 'where', $options );

		// SQL-specific options
		$this->assertArrayHasKey( 'include-schema', $options );
		$this->assertArrayHasKey( 'drop-tables', $options );
		$this->assertArrayHasKey( 'no-transaction', $options );

		// General options
		$this->assertArrayHasKey( 'compress', $options );
		$this->assertArrayHasKey( 'config', $options );
		$this->assertArrayHasKey( 'dry-run', $options );
	}

	/**
	 * Test format validation
	 */
	public function testFormatValidation(): void
	{
		$validFormats = ['sql', 'json', 'csv', 'yaml'];

		foreach( $validFormats as $format )
		{
			// Create reflection to test protected method
			$reflection = new \ReflectionClass( $this->command );
			$method = $reflection->getMethod( 'parseExportOptions' );
			$method->setAccessible( true );

			// Set up input
			$input = new Input( ['--format=' . $format] );
			$this->command->setInput( $input );

			// Parse options
			$options = $method->invoke( $this->command );

			$this->assertEquals( $format, $options['format'] );
		}
	}

	/**
	 * Test table filtering options parsing
	 */
	public function testTableFilteringParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseExportOptions' );
		$method->setAccessible( true );

		// Test tables option
		$input = new Input( ['--tables=users,posts,comments'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertIsArray( $options['tables'] );
		$this->assertContains( 'users', $options['tables'] );
		$this->assertContains( 'posts', $options['tables'] );
		$this->assertContains( 'comments', $options['tables'] );

		// Test exclude option
		$input = new Input( ['--exclude=logs,sessions'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertIsArray( $options['exclude'] );
		$this->assertContains( 'logs', $options['exclude'] );
		$this->assertContains( 'sessions', $options['exclude'] );
	}

	/**
	 * Test limit option parsing
	 */
	public function testLimitOptionParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseExportOptions' );
		$method->setAccessible( true );

		$input = new Input( ['--limit=100'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertEquals( 100, $options['limit'] );
	}

	/**
	 * Test WHERE conditions parsing
	 */
	public function testWhereConditionsParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseExportOptions' );
		$method->setAccessible( true );

		$input = new Input( ['--where=users:active=1'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertIsArray( $options['where'] );
		$this->assertArrayHasKey( 'users', $options['where'] );
		$this->assertEquals( 'active=1', $options['where']['users'] );
	}

	/**
	 * Test SQL-specific options parsing
	 */
	public function testSqlOptionsParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseExportOptions' );
		$method->setAccessible( true );

		$input = new Input( [
			'--include-schema',
			'--drop-tables',
			'--no-transaction'
		] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertTrue( $options['include_schema'] );
		$this->assertTrue( $options['drop_tables'] );
		$this->assertFalse( $options['use_transaction'] );
	}

	/**
	 * Test compress option parsing
	 */
	public function testCompressOptionParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseExportOptions' );
		$method->setAccessible( true );

		$input = new Input( ['--compress'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertTrue( $options['compress'] );
	}

	/**
	 * Test file size formatting
	 */
	public function testFileSizeFormatting(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'formatFileSize' );
		$method->setAccessible( true );

		// Test different sizes
		$this->assertEquals( '100 B', $method->invoke( $this->command, 100 ) );
		$this->assertEquals( '1 KB', $method->invoke( $this->command, 1024 ) );
		$this->assertEquals( '1.5 KB', $method->invoke( $this->command, 1536 ) );
		$this->assertEquals( '1 MB', $method->invoke( $this->command, 1048576 ) );
		$this->assertEquals( '1 GB', $method->invoke( $this->command, 1073741824 ) );
	}

	/**
	 * Test default output path determination
	 */
	public function testDefaultOutputPath(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'determineOutputPath' );
		$method->setAccessible( true );

		// Initialize input with no output option (to get default paths)
		$input = new Input( [] );
		$this->command->setInput( $input );

		// Test default paths for each format
		$basePath = '/test/project';

		// SQL format
		$path = $method->invoke( $this->command, $basePath, 'sql' );
		$this->assertStringContainsString( 'db/data_dump.sql', $path );

		// JSON format
		$path = $method->invoke( $this->command, $basePath, 'json' );
		$this->assertStringContainsString( 'db/data_dump.json', $path );

		// CSV format
		$path = $method->invoke( $this->command, $basePath, 'csv' );
		$this->assertStringContainsString( 'db/csv_export', $path );

		// YAML format
		$path = $method->invoke( $this->command, $basePath, 'yaml' );
		$this->assertStringContainsString( 'db/data_dump.yaml', $path );
	}

	/**
	 * Test custom output path handling
	 */
	public function testCustomOutputPath(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'determineOutputPath' );
		$method->setAccessible( true );

		// Set custom output
		$input = new Input( ['--output=/custom/path/backup.sql'] );
		$this->command->setInput( $input );

		$basePath = '/test/project';
		$path = $method->invoke( $this->command, $basePath, 'sql' );

		$this->assertEquals( '/custom/path/backup.sql', $path );
	}

	/**
	 * Test relative path resolution
	 */
	public function testRelativePathResolution(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'determineOutputPath' );
		$method->setAccessible( true );

		// Set relative output
		$input = new Input( ['--output=backups/data.sql'] );
		$this->command->setInput( $input );

		$basePath = '/test/project';
		$path = $method->invoke( $this->command, $basePath, 'sql' );

		$this->assertEquals( '/test/project/backups/data.sql', $path );
	}

	/**
	 * Test output and input are properly initialized
	 */
	public function testOutputAndInputSetup(): void
	{
		$output = new Output( false );
		$input = new Input( [] );

		$this->command->setOutput( $output );
		$this->command->setInput( $input );

		// Use reflection to check protected properties
		$reflection = new \ReflectionClass( $this->command );

		$outputProperty = $reflection->getProperty( 'output' );
		$outputProperty->setAccessible( true );
		$this->assertSame( $output, $outputProperty->getValue( $this->command ) );

		$inputProperty = $reflection->getProperty( 'input' );
		$inputProperty->setAccessible( true );
		$this->assertSame( $input, $inputProperty->getValue( $this->command ) );
	}
}