<?php

namespace Tests\Mvc\Cli\Commands\Data;

use Neuron\Mvc\Cli\Commands\Data\RestoreCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RestoreCommand
 */
class RestoreCommandUnitTest extends TestCase
{
	private RestoreCommand $command;

	protected function setUp(): void
	{
		$this->command = new RestoreCommand();
	}

	/**
	 * Test command name is correct
	 */
	public function testCommandName(): void
	{
		$this->assertEquals( 'db:data:restore', $this->command->getName() );
	}

	/**
	 * Test command description is set
	 */
	public function testCommandDescription(): void
	{
		$this->assertEquals(
			'Restore database data from various formats (SQL, JSON, CSV, YAML)',
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

		// Input options
		$this->assertArrayHasKey( 'input', $options );
		$this->assertArrayHasKey( 'format', $options );

		// Table selection options
		$this->assertArrayHasKey( 'tables', $options );
		$this->assertArrayHasKey( 'exclude', $options );

		// Conflict resolution options
		$this->assertArrayHasKey( 'conflict-mode', $options );
		$this->assertArrayHasKey( 'clear-tables', $options );

		// Safety options
		$this->assertArrayHasKey( 'force', $options );
		$this->assertArrayHasKey( 'confirm', $options );
		$this->assertArrayHasKey( 'dry-run', $options );
		$this->assertArrayHasKey( 'backup-first', $options );

		// Transaction options
		$this->assertArrayHasKey( 'no-transaction', $options );
		$this->assertArrayHasKey( 'no-foreign-keys', $options );

		// Execution options
		$this->assertArrayHasKey( 'batch-size', $options );
		$this->assertArrayHasKey( 'stop-on-error', $options );
		$this->assertArrayHasKey( 'continue-on-error', $options );

		// General options
		$this->assertArrayHasKey( 'config', $options );
		$this->assertArrayHasKey( 'verify', $options );
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
			$method = $reflection->getMethod( 'parseImportOptions' );
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
	 * Test conflict mode validation
	 */
	public function testConflictModeValidation(): void
	{
		$validModes = ['replace', 'append', 'skip'];

		foreach( $validModes as $mode )
		{
			// Create reflection to test protected method
			$reflection = new \ReflectionClass( $this->command );
			$method = $reflection->getMethod( 'parseImportOptions' );
			$method->setAccessible( true );

			// Set up input
			$input = new Input( ['--conflict-mode=' . $mode] );
			$this->command->setInput( $input );

			// Parse options
			$options = $method->invoke( $this->command );

			$this->assertEquals( $mode, $options['conflict_mode'] );
		}
	}

	/**
	 * Test table filtering options parsing
	 */
	public function testTableFilteringParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseImportOptions' );
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
	 * Test clear-tables option sets conflict mode to replace
	 */
	public function testClearTablesOption(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseImportOptions' );
		$method->setAccessible( true );

		$input = new Input( ['--clear-tables'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertEquals( 'replace', $options['conflict_mode'] );
	}

	/**
	 * Test transaction options parsing
	 */
	public function testTransactionOptionsParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseImportOptions' );
		$method->setAccessible( true );

		$input = new Input( [
			'--no-transaction',
			'--no-foreign-keys'
		] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertFalse( $options['use_transaction'] );
		$this->assertFalse( $options['disable_foreign_keys'] );
	}

	/**
	 * Test batch size option parsing
	 */
	public function testBatchSizeOptionParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseImportOptions' );
		$method->setAccessible( true );

		$input = new Input( ['--batch-size=500'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertEquals( 500, $options['batch_size'] );
	}

	/**
	 * Test error handling options parsing
	 */
	public function testErrorHandlingOptionsParsing(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseImportOptions' );
		$method->setAccessible( true );

		// Test continue on error
		$input = new Input( ['--continue-on-error'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertFalse( $options['stop_on_error'] );

		// Test stop on error (default should be true)
		$input = new Input( [] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertTrue( $options['stop_on_error'] ); // Fixed: default is now correctly true

		// Test explicit stop-on-error flag
		$input = new Input( ['--stop-on-error'] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		$this->assertTrue( $options['stop_on_error'] ); // Explicitly set to stop on error
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
	 * Test invalid format warning
	 */
	public function testInvalidFormatWarning(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseImportOptions' );
		$method->setAccessible( true );

		// Mock output to capture warnings
		$output = $this->createMock( Output::class );
		$output->expects( $this->once() )
			->method( 'warning' )
			->with( $this->stringContains( 'Invalid format' ) );

		$this->command->setOutput( $output );

		// Set invalid format
		$input = new Input( ['--format=invalid'] );
		$this->command->setInput( $input );

		// Parse options - should trigger warning
		$options = $method->invoke( $this->command );

		// Format should be null (auto-detect)
		$this->assertNull( $options['format'] );
	}

	/**
	 * Test invalid conflict mode warning
	 */
	public function testInvalidConflictModeWarning(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseImportOptions' );
		$method->setAccessible( true );

		// Mock output to capture warnings
		$output = $this->createMock( Output::class );
		$output->expects( $this->once() )
			->method( 'warning' )
			->with( $this->stringContains( 'Invalid conflict mode' ) );

		$this->command->setOutput( $output );

		// Set invalid conflict mode
		$input = new Input( ['--conflict-mode=invalid'] );
		$this->command->setInput( $input );

		// Parse options - should trigger warning
		$options = $method->invoke( $this->command );

		// Should default to 'replace'
		$this->assertEquals( 'replace', $options['conflict_mode'] );
	}

	/**
	 * Test auto-detect format (null)
	 */
	public function testAutoDetectFormat(): void
	{
		// Create reflection to test protected method
		$reflection = new \ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'parseImportOptions' );
		$method->setAccessible( true );

		// No format specified
		$input = new Input( [] );
		$this->command->setInput( $input );
		$options = $method->invoke( $this->command );

		// Format should be null for auto-detection
		$this->assertNull( $options['format'] );
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