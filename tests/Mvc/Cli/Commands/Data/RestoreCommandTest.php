<?php

namespace Tests\Mvc\Cli\Commands\Data;

use Neuron\Mvc\Cli\Commands\Data\RestoreCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Cli\IO\TestInputReader;
use PHPUnit\Framework\TestCase;

class RestoreCommandTest extends TestCase
{
	private RestoreCommand $command;
	private Output $output;
	private TestInputReader $inputReader;
	private string $tempDir;

	protected function setUp(): void
	{
		$this->command = new RestoreCommand();
		$this->output = new Output( false ); // No colors in tests
		$this->inputReader = new TestInputReader();

		$this->command->setOutput( $this->output );
		$this->command->setInputReader( $this->inputReader );

		// Create temp directory for tests
		$this->tempDir = sys_get_temp_dir() . '/neuron_mvc_restore_test_' . uniqid();
		mkdir( $this->tempDir );
		mkdir( $this->tempDir . '/db' );
	}

	protected function tearDown(): void
	{
		// Clean up temp directory
		if( is_dir( $this->tempDir ) )
		{
			$this->recursiveRemoveDirectory( $this->tempDir );
		}
	}

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
			is_dir( $path ) ? $this->recursiveRemoveDirectory( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function testGetName(): void
	{
		$this->assertEquals( 'db:data:restore', $this->command->getName() );
	}

	public function testGetDescription(): void
	{
		$this->assertEquals(
			'Restore database data from various formats (SQL, JSON, CSV, YAML)',
			$this->command->getDescription()
		);
	}

	public function testConfigure(): void
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

	public function testExecuteWithMissingConfig(): void
	{
		$input = new Input( [] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 1, $exitCode );
	}

	public function testExecuteWithMissingInput(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// No input file specified
		$input = new Input( [
			'--config=' . $this->tempDir
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 1, $exitCode );
	}

	public function testExecuteWithDryRun(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test_dump.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id, name) VALUES (1, 'Test');" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputFile,
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		// Dry run should succeed
		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithNonExistentInput(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=/nonexistent/file.sql'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		// Should fail with missing file
		$this->assertEquals( 1, $exitCode );
	}

	public function testExecuteWithFormatOption(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$formats = ['sql', 'json', 'yaml'];

		foreach( $formats as $format )
		{
			// Create appropriate test file
			$inputFile = $this->tempDir . "/test.{$format}";
			$this->createTestFile( $inputFile, $format );

			$input = new Input( [
				'--config=' . $this->tempDir,
				'--input=' . $inputFile,
				'--format=' . $format,
				'--dry-run'
			] );
			$this->command->setInput( $input );

			$exitCode = $this->command->execute();

			// All formats should be accepted
			$this->assertEquals( 0, $exitCode, "Format {$format} should be accepted" );
		}
	}

	public function testExecuteWithCsvFormat(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create CSV directory
		$csvDir = $this->tempDir . '/csv_import';
		mkdir( $csvDir );

		// Create CSV file
		$csvFile = $csvDir . '/users.csv';
		file_put_contents( $csvFile, "id,name\n1,John\n2,Jane" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $csvDir,
			'--format=csv',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithTableSelection(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id) VALUES (1);" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputFile,
			'--tables=users,posts',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithTableExclusion(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id) VALUES (1);" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputFile,
			'--exclude=logs,sessions',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithConflictModes(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id) VALUES (1);" );

		$modes = ['replace', 'append', 'skip'];

		foreach( $modes as $mode )
		{
			$input = new Input( [
				'--config=' . $this->tempDir,
				'--input=' . $inputFile,
				'--conflict-mode=' . $mode,
				'--dry-run'
			] );
			$this->command->setInput( $input );

			$exitCode = $this->command->execute();

			$this->assertEquals( 0, $exitCode, "Conflict mode {$mode} should be accepted" );
		}
	}

	public function testExecuteWithClearTables(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id) VALUES (1);" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputFile,
			'--clear-tables',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithBatchSize(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id) VALUES (1);" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputFile,
			'--batch-size=500',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithTransactionOptions(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id) VALUES (1);" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputFile,
			'--no-transaction',
			'--no-foreign-keys',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithContinueOnError(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id) VALUES (1);" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputFile,
			'--continue-on-error',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithVerify(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create test input file
		$inputFile = $this->tempDir . '/test.sql';
		file_put_contents( $inputFile, "INSERT INTO users (id) VALUES (1);" );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputFile,
			'--verify',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	/**
	 * Test actual database restore (integration test)
	 */
	public function testActualDatabaseRestore(): void
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		// Create config with database
		$config = $this->createTestConfigWithDatabase();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create SQLite database
		$dbPath = $this->tempDir . '/test.db';
		$this->createTestDatabase( $dbPath );

		// Create SQL dump file
		$inputPath = $this->tempDir . '/dump.sql';
		$sql = "
			DELETE FROM users;
			INSERT INTO users (id, name, email) VALUES
			(10, 'Restored User', 'restored@example.com');
		";
		file_put_contents( $inputPath, $sql );

		// Set force to skip confirmation
		$input = new Input( [
			'--config=' . $this->tempDir,
			'--input=' . $inputPath,
			'--force'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );

		// Verify data was restored
		$pdo = new \PDO( 'sqlite:' . $dbPath );
		$result = $pdo->query( "SELECT * FROM users WHERE id = 10" )->fetch();
		$this->assertEquals( 'Restored User', $result['name'] );
	}

	/**
	 * Create a minimal test configuration
	 *
	 * @return string
	 */
	private function createTestConfig(): string
	{
		return <<<YAML
database:
  adapter: sqlite
  name: :memory:
  charset: utf8

migrations:
  paths:
    - {$this->tempDir}/migrations
  table: phinx_log

cache:
  enabled: false
YAML;
	}

	/**
	 * Create a test configuration with actual database
	 *
	 * @return string
	 */
	private function createTestConfigWithDatabase(): string
	{
		$dbPath = $this->tempDir . '/test.db';

		return <<<YAML
database:
  adapter: sqlite
  name: {$dbPath}
  charset: utf8

migrations:
  paths:
    - {$this->tempDir}/migrations
  table: phinx_log

cache:
  enabled: false
YAML;
	}

	/**
	 * Create a test file with appropriate content for format
	 *
	 * @param string $filePath
	 * @param string $format
	 */
	private function createTestFile( string $filePath, string $format ): void
	{
		switch( $format )
		{
			case 'sql':
				file_put_contents( $filePath, "INSERT INTO users (id) VALUES (1);" );
				break;

			case 'json':
				$data = [
					'metadata' => ['exported_at' => date( 'Y-m-d H:i:s' )],
					'data' => ['users' => [['id' => 1]]]
				];
				file_put_contents( $filePath, json_encode( $data ) );
				break;

			case 'yaml':
				$yaml = "metadata:\n  exported_at: '2024-01-01'\ndata:\n  users:\n    - id: 1";
				file_put_contents( $filePath, $yaml );
				break;
		}
	}

	/**
	 * Create a test SQLite database with sample data
	 *
	 * @param string $dbPath
	 */
	private function createTestDatabase( string $dbPath ): void
	{
		$pdo = new \PDO( 'sqlite:' . $dbPath );

		// Create users table
		$pdo->exec( '
			CREATE TABLE users (
				id INTEGER PRIMARY KEY,
				name TEXT NOT NULL,
				email TEXT NOT NULL,
				created_at TEXT DEFAULT CURRENT_TIMESTAMP
			)
		' );

		// Insert sample data
		$pdo->exec( "
			INSERT INTO users (name, email) VALUES
			('John Doe', 'john@example.com'),
			('Jane Smith', 'jane@example.com')
		" );

		// Create migration table
		$pdo->exec( '
			CREATE TABLE phinx_log (
				version BIGINT NOT NULL PRIMARY KEY,
				migration_name VARCHAR(100),
				start_time TIMESTAMP,
				end_time TIMESTAMP,
				breakpoint BOOLEAN DEFAULT 0
			)
		' );
	}
}