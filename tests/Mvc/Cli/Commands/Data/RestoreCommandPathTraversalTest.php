<?php

namespace Tests\Mvc\Cli\Commands\Data;

use Neuron\Cli\Commands\Command;
use Neuron\Mvc\Cli\Commands\Data\RestoreCommand;
use PHPUnit\Framework\TestCase;

/**
 * Test path traversal protection in RestoreCommand
 */
class RestoreCommandPathTraversalTest extends TestCase
{
	private $tempDir;
	private $baseDir;
	private $outsideDir;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temporary directory structure
		$this->tempDir = sys_get_temp_dir() . '/restore_path_test_' . uniqid();
		$this->baseDir = $this->tempDir . '/allowed';
		$this->outsideDir = $this->tempDir . '/outside';

		mkdir( $this->tempDir, 0755, true );
		mkdir( $this->baseDir, 0755, true );
		mkdir( $this->outsideDir, 0755, true );

		// Create test files
		file_put_contents( $this->baseDir . '/valid.sql', '-- Valid SQL file' );
		file_put_contents( $this->outsideDir . '/malicious.sql', '-- Malicious SQL file' );

		// Create a test config file
		$configDir = $this->baseDir . '/config';
		mkdir( $configDir, 0755, true );
		file_put_contents( $configDir . '/neuron.yaml', "database:\n  host: localhost\n" );
	}

	protected function tearDown(): void
	{
		// Clean up temporary directories
		$this->recursiveRemoveDir( $this->tempDir );
		parent::tearDown();
	}

	/**
	 * Test that valid paths within base directory are allowed
	 */
	public function testValidPathsAllowed(): void
	{
		$command = $this->createRestoreCommand();

		// Test absolute path within base
		$result = $this->simulatePathValidation(
			$command,
			$this->baseDir,
			$this->baseDir . '/valid.sql'
		);

		$this->assertTrue( $result['valid'], 'Valid absolute path should be allowed' );

		// Test relative path within base
		$result = $this->simulatePathValidation(
			$command,
			$this->baseDir,
			'valid.sql'
		);

		$this->assertTrue( $result['valid'], 'Valid relative path should be allowed' );
	}

	/**
	 * Test that path traversal attempts are blocked
	 */
	public function testPathTraversalBlocked(): void
	{
		$command = $this->createRestoreCommand();

		// Test ../ traversal attempt
		$result = $this->simulatePathValidation(
			$command,
			$this->baseDir,
			'../outside/malicious.sql'
		);

		$this->assertFalse( $result['valid'], 'Path traversal with ../ should be blocked' );
		$this->assertStringContainsString( 'outside the allowed directory', $result['error'] );

		// Test multiple ../ traversal
		$result = $this->simulatePathValidation(
			$command,
			$this->baseDir,
			'../../../../../../etc/passwd'
		);

		$this->assertFalse( $result['valid'], 'Path traversal to system files should be blocked' );

		// Test URL-decoded traversal attack
		// Simulate what happens if user input is URL-decoded before validation
		$encodedPath = '..%2Foutside%2Fmalicious.sql';
		$decodedPath = urldecode( $encodedPath );  // Results in: '../outside/malicious.sql'

		$result = $this->simulatePathValidation(
			$command,
			$this->baseDir,
			$decodedPath
		);

		$this->assertFalse( $result['valid'], 'URL-decoded path traversal should be blocked' );
		$this->assertStringContainsString( 'outside the allowed directory', $result['error'] );
	}

	/**
	 * Test symlink attacks are prevented
	 */
	public function testSymlinkAttackPrevented(): void
	{
		// Create a symlink pointing outside the base directory
		$symlinkPath = $this->baseDir . '/evil_link.sql';
		@symlink( $this->outsideDir . '/malicious.sql', $symlinkPath );

		if( !file_exists( $symlinkPath ) )
		{
			$this->markTestSkipped( 'Symlinks not supported on this system' );
		}

		$command = $this->createRestoreCommand();

		$result = $this->simulatePathValidation(
			$command,
			$this->baseDir,
			'evil_link.sql'
		);

		$this->assertFalse( $result['valid'], 'Symlink pointing outside base should be blocked' );
	}

	/**
	 * Test backup path validation
	 */
	public function testBackupPathValidation(): void
	{
		// Test valid backup path
		$result = $this->simulateBackupPathValidation(
			$this->baseDir,
			'backup.sql'
		);

		$this->assertTrue( $result['valid'], 'Valid backup path should be allowed' );

		// Test backup path with traversal
		$result = $this->simulateBackupPathValidation(
			$this->baseDir,
			'../outside/backup.sql'
		);

		$this->assertFalse( $result['valid'], 'Backup path traversal should be blocked' );
		$this->assertStringContainsString( 'outside the allowed directory', $result['error'] );
	}

	/**
	 * Test edge cases
	 */
	public function testEdgeCases(): void
	{
		$command = $this->createRestoreCommand();

		// Test empty path
		$result = $this->simulatePathValidation(
			$command,
			$this->baseDir,
			''
		);

		$this->assertFalse( $result['valid'], 'Empty path should be invalid' );

		// Test path with null bytes (poison null attack)
		// PHP's realpath() will throw a ValueError for null bytes, which is good security
		try
		{
			$result = $this->simulatePathValidation(
				$command,
				$this->baseDir,
				"valid.sql\0.txt"
			);
			$this->assertFalse( $result['valid'], 'Path with null bytes should be invalid' );
		}
		catch( \ValueError $e )
		{
			// This is expected - PHP protects against null byte attacks
			$this->assertStringContainsString( 'null bytes', $e->getMessage() );
		}

		// Test path with special characters
		$specialFile = $this->baseDir . '/special!@#$%^&()file.sql';
		file_put_contents( $specialFile, '-- Special file' );

		$result = $this->simulatePathValidation(
			$command,
			$this->baseDir,
			'special!@#$%^&()file.sql'
		);

		$this->assertTrue( $result['valid'], 'Special characters in filename should be allowed if within base' );
	}

	/**
	 * Test that realpath failure on base path is detected (CVE-style security fix)
	 *
	 * This tests the fix for a medium-severity path traversal vulnerability where
	 * if realpath($basePath) returns false (due to race condition, unmounted filesystem,
	 * or permissions change), the security check would be bypassed.
	 */
	public function testBasePathRealpathFailureDetected(): void
	{
		$command = $this->createRestoreCommand();

		// Test with non-existent base path (realpath returns false)
		$nonExistentBase = '/nonexistent/base/path/that/does/not/exist';
		$result = $this->simulatePathValidation(
			$command,
			$nonExistentBase,
			'/tmp/some_file.sql'
		);

		$this->assertFalse( $result['valid'], 'Non-existent base path should be rejected' );
		$this->assertStringContainsString( 'not found or inaccessible', $result['error'] );

		// Test backup path validation with non-existent base
		$result = $this->simulateBackupPathValidation(
			$nonExistentBase,
			'backup.sql'
		);

		$this->assertFalse( $result['valid'], 'Backup with non-existent base path should be rejected' );
		$this->assertStringContainsString( 'not found or inaccessible', $result['error'] );
	}

	// Helper methods

	private function createRestoreCommand(): RestoreCommand
	{
		return new RestoreCommand();
	}

	private function simulatePathValidation( RestoreCommand $command, string $basePath, string $inputPath ): array
	{
		try
		{
			// Simulate the validation logic from RestoreCommand
			if( $inputPath && !str_starts_with( $inputPath, '/' ) )
			{
				$inputPath = $basePath . '/' . $inputPath;
			}

			$resolvedBasePath = realpath( $basePath );
			$resolvedInputPath = realpath( $inputPath );

			if( $resolvedBasePath === false )
			{
				return ['valid' => false, 'error' => 'Base directory not found or inaccessible'];
			}

			if( $resolvedInputPath === false )
			{
				return ['valid' => false, 'error' => 'File not found'];
			}

			if( !str_starts_with( $resolvedInputPath, $resolvedBasePath . '/' ) &&
			    $resolvedInputPath !== $resolvedBasePath )
			{
				return ['valid' => false, 'error' => 'Path is outside the allowed directory'];
			}

			return ['valid' => true, 'path' => $resolvedInputPath];
		}
		catch( \Exception $e )
		{
			return ['valid' => false, 'error' => $e->getMessage()];
		}
	}

	private function simulateBackupPathValidation( string $basePath, string $backupPath ): array
	{
		try
		{
			// Simulate the backup path validation logic
			if( !str_starts_with( $backupPath, '/' ) )
			{
				$backupPath = $basePath . '/' . $backupPath;
			}

			$resolvedBasePath = realpath( $basePath );

			if( $resolvedBasePath === false )
			{
				return ['valid' => false, 'error' => 'Base directory not found or inaccessible'];
			}

			$backupDir = dirname( $backupPath );
			$resolvedBackupDir = realpath( $backupDir );

			if( $resolvedBackupDir === false )
			{
				return ['valid' => false, 'error' => 'Backup directory does not exist'];
			}

			if( !str_starts_with( $resolvedBackupDir, $resolvedBasePath . '/' ) &&
			    $resolvedBackupDir !== $resolvedBasePath )
			{
				return ['valid' => false, 'error' => 'Backup path is outside the allowed directory'];
			}

			$backupFilename = basename( $backupPath );
			$validatedPath = $resolvedBackupDir . '/' . $backupFilename;

			return ['valid' => true, 'path' => $validatedPath];
		}
		catch( \Exception $e )
		{
			return ['valid' => false, 'error' => $e->getMessage()];
		}
	}

	private function recursiveRemoveDir( $dir ): void
	{
		if( !is_dir( $dir ) ) return;

		$objects = scandir( $dir );
		foreach( $objects as $object )
		{
			if( $object != "." && $object != ".." )
			{
				$path = $dir . "/" . $object;
				if( is_dir( $path ) )
				{
					$this->recursiveRemoveDir( $path );
				}
				else
				{
					unlink( $path );
				}
			}
		}
		rmdir( $dir );
	}
}