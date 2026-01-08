<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that SQL splitter preserves comment-like lines inside string literals
 */
class SqlStringCommentPreservationTest extends TestCase
{
	private $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

		// Ensure clean state by resetting AdapterFactory at start of each test
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );
	}

	protected function tearDown(): void
	{
		// Reset AdapterFactory to null to ensure clean state
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Test that lines starting with -- inside strings are preserved
	 */
	public function testPreservesDashDashInsideStrings(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		// Test SQL with multi-line string containing comment-like lines
		$sql = "INSERT INTO messages (id, content) VALUES (1, 'Hello\n-- This is NOT a comment\nIt is part of the string\nWorld');";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 1, $statements );

		// Verify the statement contains the "comment" line
		$this->assertStringContainsString( "-- This is NOT a comment", $statements[0] );
		$this->assertStringContainsString( "It is part of the string", $statements[0] );
	}

	/**
	 * Test that lines starting with # inside strings are preserved
	 */
	public function testPreservesHashInsideStrings(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		$sql = "INSERT INTO posts (title, body) VALUES ('My Post', 'First line\n# This looks like a comment\n# But it is not\nLast line');";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( "# This looks like a comment", $statements[0] );
		$this->assertStringContainsString( "# But it is not", $statements[0] );
	}

	/**
	 * Test that actual comments outside strings are still skipped
	 */
	public function testSkipsRealComments(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		$sql = "-- This is a real comment\n" .
		       "INSERT INTO users (name) VALUES ('John');\n" .
		       "# Another real comment\n" .
		       "INSERT INTO users (name) VALUES ('Jane');";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 2, $statements );

		// Real comments should be skipped
		$this->assertStringNotContainsString( "This is a real comment", $statements[0] );
		$this->assertStringNotContainsString( "Another real comment", $statements[1] );

		// But the INSERT statements should be present
		$this->assertStringContainsString( "INSERT INTO users", $statements[0] );
		$this->assertStringContainsString( "John", $statements[0] );
		$this->assertStringContainsString( "Jane", $statements[1] );
	}

	/**
	 * Test complex SQL with mixed real comments and comment-like strings
	 */
	public function testComplexMixedCommentsAndStrings(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		$sql = "-- Database setup\n" .
		       "CREATE TABLE logs (id INT, entry TEXT);\n" .
		       "-- Insert test data\n" .
		       "INSERT INTO logs VALUES (1, 'Log entry:\n-- Processing started\n-- Step 1 complete\n# Step 2 complete');\n" .
		       "# Another comment\n" .
		       "INSERT INTO logs VALUES (2, 'Error log:\n# ERROR: Failed\n-- Retry attempted');\n" .
		       "-- Final comment";

		$statements = $method->invoke( $importer, $sql );

		// Should have CREATE TABLE and 2 INSERTs
		$this->assertCount( 3, $statements );

		// First statement is CREATE TABLE
		$this->assertStringContainsString( "CREATE TABLE", $statements[0] );

		// Second statement should preserve the comment-like lines in the string
		$this->assertStringContainsString( "-- Processing started", $statements[1] );
		$this->assertStringContainsString( "-- Step 1 complete", $statements[1] );
		$this->assertStringContainsString( "# Step 2 complete", $statements[1] );

		// Third statement should also preserve comment-like lines
		$this->assertStringContainsString( "# ERROR: Failed", $statements[2] );
		$this->assertStringContainsString( "-- Retry attempted", $statements[2] );

		// Real comments should not appear in any statement
		foreach( $statements as $stmt )
		{
			$this->assertStringNotContainsString( "Database setup", $stmt );
			$this->assertStringNotContainsString( "Insert test data", $stmt );
			$this->assertStringNotContainsString( "Another comment", $stmt );
			$this->assertStringNotContainsString( "Final comment", $stmt );
		}
	}

	/**
	 * Test that empty lines inside strings are preserved
	 */
	public function testPreservesEmptyLinesInStrings(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		// String with empty lines that might look like they should be skipped
		$sql = "INSERT INTO documents (content) VALUES ('Line 1\n\n-- Comment-like line\n\nLine 4');";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 1, $statements );

		// The string should contain all lines including empty ones
		$this->assertStringContainsString( "Line 1\n\n-- Comment-like line\n\nLine 4", $statements[0] );
	}

	/**
	 * Test string that spans statement boundary with comment-like content
	 */
	public function testMultiStatementWithCommentLikeStrings(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		$sql = "INSERT INTO t1 VALUES ('First\n-- not a comment');\n" .
		       "INSERT INTO t2 VALUES ('Second\n# also not a comment');\n" .
		       "-- This is a real comment between statements\n" .
		       "INSERT INTO t3 VALUES ('Third\n-- another fake comment\n# and another');";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 3, $statements );

		// First statement
		$this->assertStringContainsString( "t1", $statements[0] );
		$this->assertStringContainsString( "-- not a comment", $statements[0] );

		// Second statement
		$this->assertStringContainsString( "t2", $statements[1] );
		$this->assertStringContainsString( "# also not a comment", $statements[1] );

		// Third statement
		$this->assertStringContainsString( "t3", $statements[2] );
		$this->assertStringContainsString( "-- another fake comment", $statements[2] );
		$this->assertStringContainsString( "# and another", $statements[2] );

		// Real comment should not appear
		foreach( $statements as $stmt )
		{
			$this->assertStringNotContainsString( "real comment between", $stmt );
		}
	}

	/**
	 * Test edge case: string starts immediately with comment-like line
	 */
	public function testStringStartingWithCommentPattern(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		// String value starts with comment pattern
		$sql = "INSERT INTO config (key, value) VALUES ('script', '-- Script configuration\n-- Version: 1.0\n# Author: Test');";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( "-- Script configuration", $statements[0] );
		$this->assertStringContainsString( "-- Version: 1.0", $statements[0] );
		$this->assertStringContainsString( "# Author: Test", $statements[0] );
	}

	/**
	 * Test with different quote styles
	 */
	public function testCommentPatternsInDoubleQuotedStrings(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'postgresql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		// PostgreSQL style with double quotes for identifiers and single quotes for strings
		$sql = 'INSERT INTO "messages" ("text") VALUES (\'Line 1\n-- preserved line\n# another preserved line\');' . "\n" .
		       '-- Real comment' . "\n" .
		       'INSERT INTO "logs" ("entry") VALUES (\'Start\n-- middle\nEnd\');';

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 2, $statements );

		// Both statements should preserve their comment-like content
		$this->assertStringContainsString( "-- preserved line", $statements[0] );
		$this->assertStringContainsString( "# another preserved line", $statements[0] );
		$this->assertStringContainsString( "-- middle", $statements[1] );

		// Real comment should be skipped
		$this->assertStringNotContainsString( "Real comment", $statements[0] );
		$this->assertStringNotContainsString( "Real comment", $statements[1] );
	}

	// Helper methods

	private function createMockConfig(): Config
	{
		return new Config( [
			'paths' => [
				'migrations' => '/test/migrations'
			],
			'environments' => [
				'testing' => [
					'adapter' => 'mysql',
					'host' => 'localhost',
					'name' => 'test_db',
					'user' => 'root',
					'pass' => '',
					'port' => 3306
				]
			]
		] );
	}

	private function mockAdapterFactory( $mockAdapter ): void
	{
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );

		$mockFactory = $this->createMock( AdapterFactory::class );
		$mockFactory->method( 'getAdapter' )->willReturn( $mockAdapter );

		$instanceProperty->setValue( null, $mockFactory );
	}
}
