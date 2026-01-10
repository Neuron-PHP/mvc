<?php

namespace Tests\Mvc\Cli\Commands\Data;

use Neuron\Mvc\Cli\Commands\Data\DumpCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Cli\IO\TestInputReader;
use PHPUnit\Framework\TestCase;

class DumpCommandTest extends TestCase
{
	private DumpCommand $command;
	private Output $output;
	private TestInputReader $inputReader;
	private string $tempDir;

	protected function setUp(): void
	{
		$this->command = new DumpCommand();
		$this->output = new Output( false ); // No colors in tests
		$this->inputReader = new TestInputReader();

		$this->command->setOutput( $this->output );
		$this->command->setInputReader( $this->inputReader );

		// Create temp directory for tests
		$this->tempDir = sys_get_temp_dir() . '/neuron_mvc_data_test_' . uniqid();
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
		$this->assertEquals( 'db:data:dump', $this->command->getName() );
	}

	public function testGetDescription(): void
	{
		$this->assertEquals(
			'Export database data in various formats (SQL, JSON, CSV, YAML)',
			$this->command->getDescription()
		);
	}

	public function testConfigure(): void
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

	public function testExecuteWithMissingConfig(): void
	{
		$input = new Input( [] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 1, $exitCode );
	}

	public function testExecuteWithDryRun(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		// Dry run should succeed without actually exporting
		$this->assertEquals( 0, $exitCode );

		// No actual export files should be created
		$this->assertFileDoesNotExist( $this->tempDir . '/db/data_dump.sql' );
	}

	public function testExecuteWithFormatOption(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$formats = ['sql', 'json', 'yaml'];

		foreach( $formats as $format )
		{
			$input = new Input( [
				'--config=' . $this->tempDir,
				'--format=' . $format,
				'--dry-run'
			] );
			$this->command->setInput( $input );

			$exitCode = $this->command->execute();

			// All formats should be accepted
			$this->assertEquals( 0, $exitCode, "Format {$format} should be accepted" );
		}
	}

	public function testExecuteWithTableSelection(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--tables=users,posts,comments',
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

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--exclude=logs,sessions',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithLimit(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--limit=100',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithInvalidLimitThrowsException(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Test with negative limit
		$input = new Input( [
			'--config=' . $this->tempDir,
			'--limit=-5',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Limit must be greater than 0' );
		$this->command->execute();
	}

	public function testExecuteWithZeroLimitThrowsException(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Test with zero limit
		$input = new Input( [
			'--config=' . $this->tempDir,
			'--limit=0',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Limit must be greater than 0' );
		$this->command->execute();
	}

	public function testExecuteWithWhereConditions(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--where=users:active=1',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithSqlOptions(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--format=sql',
			'--include-schema',
			'--drop-tables',
			'--no-transaction',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithCompression(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--compress',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithCustomOutput(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$outputPath = $this->tempDir . '/custom_backup.sql';

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--output=' . $outputPath,
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithCsvFormat(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--format=csv',
			'--output=' . $this->tempDir . '/csv_export',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		// CSV format should be handled specially
		$this->assertEquals( 0, $exitCode );
	}

	public function testExecuteWithInvalidFormat(): void
	{
		// Create minimal config
		$config = $this->createTestConfig();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--format=invalid',
			'--dry-run'
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		// Should still work, defaulting to SQL format
		$this->assertEquals( 0, $exitCode );
	}

	/**
	 * Test actual database export (integration test)
	 */
	public function testActualDatabaseExport(): void
	{
		$this->markTestSkipped( 'Requires database connection - integration test' );

		// Create config with database
		$config = $this->createTestConfigWithDatabase();
		file_put_contents( $this->tempDir . '/neuron.yaml', $config );

		// Create SQLite database
		$dbPath = $this->tempDir . '/test.db';
		$this->createTestDatabase( $dbPath );

		$outputPath = $this->tempDir . '/db/data_dump.sql';

		$input = new Input( [
			'--config=' . $this->tempDir,
			'--output=' . $outputPath
		] );
		$this->command->setInput( $input );

		$exitCode = $this->command->execute();

		$this->assertEquals( 0, $exitCode );
		$this->assertFileExists( $outputPath );

		// Verify SQL content
		$sql = file_get_contents( $outputPath );
		$this->assertStringContainsString( 'INSERT INTO', $sql );
		$this->assertStringContainsString( 'users', $sql );
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
			('Jane Smith', 'jane@example.com'),
			('Bob Johnson', 'bob@example.com')
		" );

		// Create posts table
		$pdo->exec( '
			CREATE TABLE posts (
				id INTEGER PRIMARY KEY,
				user_id INTEGER,
				title TEXT NOT NULL,
				content TEXT,
				created_at TEXT DEFAULT CURRENT_TIMESTAMP,
				FOREIGN KEY (user_id) REFERENCES users (id)
			)
		' );

		// Insert sample posts
		$pdo->exec( "
			INSERT INTO posts (user_id, title, content) VALUES
			(1, 'First Post', 'This is the first post'),
			(2, 'Second Post', 'This is the second post'),
			(1, 'Another Post', 'More content here')
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