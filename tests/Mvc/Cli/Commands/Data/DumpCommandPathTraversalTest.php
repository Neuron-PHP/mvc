<?php

namespace Tests\Mvc\Cli\Commands\Data;

use Neuron\Mvc\Cli\Commands\Data\DumpCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use PHPUnit\Framework\TestCase;

/**
 * Test that DumpCommand prevents path traversal attacks
 *
 * Verifies that the determineOutputPath method validates output paths
 * to prevent writing files outside the allowed base directory.
 */
class DumpCommandPathTraversalTest extends TestCase
{
	private string $_tempDir;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temporary directory for testing
		$this->_tempDir = sys_get_temp_dir() . '/dump_path_test_' . uniqid();
		mkdir( $this->_tempDir, 0755, true );
		mkdir( $this->_tempDir . '/config', 0755, true );

		// Create a neuron.yaml config file
		file_put_contents(
			$this->_tempDir . '/config/neuron.yaml',
			"database:\n  adapter: sqlite\n  name: test.db\n"
		);
	}

	protected function tearDown(): void
	{
		// Clean up temp directory
		$this->recursiveRemoveDirectory( $this->_tempDir );

		parent::tearDown();
	}

	/**
	 * Test that path traversal attempts with ../ are blocked
	 */
	public function testPathTraversalWithParentDirectoryIsBlocked(): void
	{
		// Create a directory structure within the temp dir to test path traversal
		// tempDir/config (base)
		// tempDir/db (allowed)
		// tempDir/outside (not allowed - simulates escaping via ../)
		mkdir( $this->_tempDir . '/db', 0755, true );
		$outsideDir = $this->_tempDir . '_outside';
		mkdir( $outsideDir, 0755, true );

		try
		{
			$this->expectException( \InvalidArgumentException::class );
			$this->expectExceptionMessage( 'Output path is outside allowed directory' );

			$command = new DumpCommand();

			// Use path traversal to escape to sibling directory
			// From config dir: ../db/../../../{outside}/dump.sql
			$relativePath = 'db/../../' . basename( $outsideDir ) . '/dump.sql';

			// Create mock input with path traversal attempt
			$input = $this->createMock( Input::class );
			$input->method( 'getOption' )
				->willReturnCallback( function( $name, $default = null ) use ( $relativePath ) {
					if( $name === 'config' ) return $this->_tempDir . '/config';
					if( $name === 'output' ) return $relativePath;
					if( $name === 'format' ) return 'sql';
					return $default;
				} );

			$output = $this->createMock( Output::class );

			// Use reflection to inject mock dependencies
			$reflection = new \ReflectionClass( $command );
			$inputProperty = $reflection->getProperty( 'input' );
			$inputProperty->setAccessible( true );
			$inputProperty->setValue( $command, $input );

			$outputProperty = $reflection->getProperty( 'output' );
			$outputProperty->setAccessible( true );
			$outputProperty->setValue( $command, $output );

			// This should throw InvalidArgumentException
			$command->execute();
		}
		finally
		{
			// Clean up
			if( is_dir( $outsideDir ) )
			{
				rmdir( $outsideDir );
			}
		}
	}

	/**
	 * Test that absolute paths outside base directory are blocked
	 */
	public function testAbsolutePathOutsideBaseDirectoryIsBlocked(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Output path is outside allowed directory' );

		$command = new DumpCommand();

		// Create mock input with absolute path outside base
		$input = $this->createMock( Input::class );
		$input->method( 'getOption' )
			->willReturnCallback( function( $name, $default = null ) {
				if( $name === 'config' ) return $this->_tempDir . '/config';
				if( $name === 'output' ) return '/tmp/malicious_dump.sql';
				if( $name === 'format' ) return 'sql';
				return $default;
			} );

		$output = $this->createMock( Output::class );

		// Use reflection to inject mock dependencies
		$reflection = new \ReflectionClass( $command );
		$inputProperty = $reflection->getProperty( 'input' );
		$inputProperty->setAccessible( true );
		$inputProperty->setValue( $command, $input );

		$outputProperty = $reflection->getProperty( 'output' );
		$outputProperty->setAccessible( true );
		$outputProperty->setValue( $command, $output );

		// This should throw InvalidArgumentException
		$command->execute();
	}

	/**
	 * Test that valid relative paths within base directory are allowed
	 */
	public function testValidRelativePathIsAllowed(): void
	{
		$command = new DumpCommand();

		// Create db directory
		mkdir( $this->_tempDir . '/db', 0755, true );

		// Create a test database
		$dbPath = $this->_tempDir . '/test.db';
		$pdo = new \PDO( 'sqlite:' . $dbPath );
		$pdo->exec( "CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)" );
		$pdo = null;

		// Update config to use test database
		file_put_contents(
			$this->_tempDir . '/config/neuron.yaml',
			"database:\n  adapter: sqlite\n  name: " . $dbPath . "\n"
		);

		// Create mock input with valid relative path
		$input = $this->createMock( Input::class );
		$input->method( 'getOption' )
			->willReturnCallback( function( $name, $default = null ) {
				if( $name === 'config' ) return $this->_tempDir . '/config';
				if( $name === 'output' ) return 'db/backup.sql';
				if( $name === 'format' ) return 'sql';
				if( $name === 'dry-run' ) return false;
				return $default;
			} );

		$output = $this->createMock( Output::class );
		$output->expects( $this->atLeastOnce() )
			->method( 'success' );

		// Use reflection to inject mock dependencies
		$reflection = new \ReflectionClass( $command );
		$inputProperty = $reflection->getProperty( 'input' );
		$inputProperty->setAccessible( true );
		$inputProperty->setValue( $command, $input );

		$outputProperty = $reflection->getProperty( 'output' );
		$outputProperty->setAccessible( true );
		$outputProperty->setValue( $command, $output );

		// This should succeed
		$result = $command->execute();
		$this->assertEquals( 0, $result, 'Valid relative path should be allowed' );

		// Verify file was created in correct location
		$this->assertFileExists( $this->_tempDir . '/db/backup.sql' );
	}

	/**
	 * Test that symlink-based path traversal is blocked
	 */
	public function testSymlinkPathTraversalIsBlocked(): void
	{
		// Create a symlink that points outside the base directory
		$outsideDir = sys_get_temp_dir() . '/outside_' . uniqid();
		mkdir( $outsideDir, 0755, true );

		try
		{
			$symlinkPath = $this->_tempDir . '/symlink_to_outside';
			symlink( $outsideDir, $symlinkPath );

			$this->expectException( \InvalidArgumentException::class );
			$this->expectExceptionMessage( 'Output path is outside allowed directory' );

			$command = new DumpCommand();

			// Create mock input using symlink to escape
			$input = $this->createMock( Input::class );
			$input->method( 'getOption' )
				->willReturnCallback( function( $name, $default = null ) {
					if( $name === 'config' ) return $this->_tempDir . '/config';
					if( $name === 'output' ) return 'symlink_to_outside/dump.sql';
					if( $name === 'format' ) return 'sql';
					return $default;
				} );

			$output = $this->createMock( Output::class );

			// Use reflection to inject mock dependencies
			$reflection = new \ReflectionClass( $command );
			$inputProperty = $reflection->getProperty( 'input' );
			$inputProperty->setAccessible( true );
			$inputProperty->setValue( $command, $input );

			$outputProperty = $reflection->getProperty( 'output' );
			$outputProperty->setAccessible( true );
			$outputProperty->setValue( $command, $output );

			// This should throw InvalidArgumentException
			$command->execute();
		}
		finally
		{
			// Clean up
			if( is_link( $symlinkPath ?? '' ) )
			{
				unlink( $symlinkPath );
			}
			$this->recursiveRemoveDirectory( $outsideDir );
		}
	}

	/**
	 * Recursively remove a directory
	 */
	private function recursiveRemoveDirectory( string $dir ): void
	{
		if( !is_dir( $dir ) )
		{
			return;
		}

		$files = array_diff( scandir( $dir ), ['.', '..'] );

		foreach( $files as $file )
		{
			$path = $dir . '/' . $file;

			if( is_dir( $path ) )
			{
				$this->recursiveRemoveDirectory( $path );
			}
			else
			{
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
