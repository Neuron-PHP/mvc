<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test SQL statement splitting with various quote escaping methods
 */
class SqlStatementSplitterTest extends TestCase
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
	 * Test splitting SQL with backslash-escaped quotes
	 */
	public function testBackslashEscapedQuotes(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		// Use reflection to test private splitSqlStatements method
		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		// Test SQL with backslash-escaped single quotes
		$sql = "INSERT INTO users (name, path) VALUES ('John\\'s file', '/usr/local/bin');\n" .
		       "INSERT INTO files (path) VALUES ('C:\\\\Users\\\\John\\'s Documents\\\\file.txt');";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 2, $statements );
		$this->assertStringContainsString( "John\\'s file", $statements[0] );
		$this->assertStringContainsString( "John\\'s Documents", $statements[1] );
	}

	/**
	 * Test splitting SQL with doubled-quote escaping
	 */
	public function testDoubledQuoteEscaping(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlite' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		// Test SQL with doubled single quotes (SQL standard)
		$sql = "INSERT INTO users (name) VALUES ('John''s file');\n" .
		       "INSERT INTO products (description) VALUES ('It''s a ''great'' product');";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 2, $statements );
		$this->assertStringContainsString( "John''s file", $statements[0] );
		$this->assertStringContainsString( "It''s a ''great'' product", $statements[1] );
	}

	/**
	 * Test mixed escaping methods in the same SQL
	 */
	public function testMixedEscapingMethods(): void
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

		// Mix of backslash and doubled quotes
		$sql = "INSERT INTO data (col1, col2) VALUES ('Value with \\'backslash', 'Value with ''doubled');\n" .
		       "UPDATE data SET path = 'C:\\\\Windows\\\\System32', name = 'O''Brien';";

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 2, $statements );
		$this->assertStringContainsString( "\\'backslash", $statements[0] );
		$this->assertStringContainsString( "''doubled", $statements[0] );
		$this->assertStringContainsString( "C:\\\\Windows\\\\System32", $statements[1] );
		$this->assertStringContainsString( "O''Brien", $statements[1] );
	}

	/**
	 * Test even vs odd number of backslashes before quotes
	 */
	public function testBackslashCounting(): void
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

		// Test various backslash counts
		// Note: In the SQL string literals below, PHP also processes backslashes,
		// so we need to double them for PHP first, then the SQL parser sees them
		$sql = "-- Zero backslashes - quote should close string\n" .
		       "INSERT INTO t1 VALUES ('normal string');\n" .
		       "-- One backslash - quote is escaped, string continues until next unescaped quote\n" .
		       "INSERT INTO t2 VALUES ('escaped\\' quote but string ends here');\n" .
		       "-- Two backslashes - backslash escapes backslash, quote closes string\n" .
		       "INSERT INTO t3 VALUES ('escaped backslash\\\\');\n" .
		       "-- Three backslashes - 2 become 1, third escapes quote, string continues\n" .
		       "INSERT INTO t4 VALUES ('three\\\\\\' this quote is escaped and text continues');\n" .
		       "-- Four backslashes - become 2 backslashes, quote closes string\n" .
		       "INSERT INTO t5 VALUES ('four\\\\\\\\');";

		$statements = $method->invoke( $importer, $sql );

		// Should get 5 INSERT statements
		$insertStatements = array_filter( $statements, function( $s ) {
			return stripos( $s, 'INSERT' ) === 0;
		} );

		$this->assertCount( 5, $insertStatements );

		// Verify each statement is complete and properly parsed
		$values = array_values( $insertStatements );
		$this->assertStringContainsString( 't1', $values[0] );
		$this->assertStringContainsString( 'normal string', $values[0] );

		$this->assertStringContainsString( 't2', $values[1] );
		$this->assertStringContainsString( 'quote but string ends here', $values[1] );

		$this->assertStringContainsString( 't3', $values[2] );
		$this->assertStringContainsString( 'escaped backslash', $values[2] );

		$this->assertStringContainsString( 't4', $values[3] );
		$this->assertStringContainsString( 'this quote is escaped and text continues', $values[3] );

		$this->assertStringContainsString( 't5', $values[4] );
		$this->assertStringContainsString( 'four', $values[4] );
	}

	/**
	 * Test with double quotes as string delimiters
	 */
	public function testDoubleQuotedStrings(): void
	{
		$mockAdapter = $this->createMock( AdapterInterface::class );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'postgres' );

		$this->mockAdapterFactory( $mockAdapter );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log' );

		$reflector = new \ReflectionClass( $importer );
		$method = $reflector->getMethod( 'splitSqlStatements' );
		$method->setAccessible( true );

		// Test with double quotes and backslash escaping
		$sql = 'INSERT INTO config (key, value) VALUES ("escaped_path", "C:\\Users\\Admin");' . "\n" .
		       'INSERT INTO config (key, value) VALUES ("quoted_text", "He said \"Hello\" to me");';

		$statements = $method->invoke( $importer, $sql );

		$this->assertCount( 2, $statements );
		$this->assertStringContainsString( '"C:\\Users\\Admin"', $statements[0] );
		$this->assertStringContainsString( '"He said \"Hello\" to me"', $statements[1] );
	}

	/**
	 * Test complex real-world SQL
	 */
	public function testComplexRealWorldSql(): void
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

		// Complex SQL with various escaping scenarios
		$sql = "-- User data with special characters\n" .
		       "INSERT INTO users (id, name, bio, path) VALUES \n" .
		       "(1, 'John O''Brien', 'Developer who\\'s passionate about SQL', '/home/john'),\n" .
		       "(2, 'Mary \"The Queen\" Smith', 'She said: \"I\\'m the best!\"', 'C:\\\\Users\\\\Mary'),\n" .
		       "(3, 'Bob\\\\nNewline', 'Uses\\nactual\\nnewlines', '/usr/bob');\n" .
		       "\n" .
		       "UPDATE settings SET value = 'It''s a wonderful \\'quoted\\' world';";

		$statements = $method->invoke( $importer, $sql );

		// Should get INSERT and UPDATE statements
		$nonCommentStatements = array_filter( $statements, function( $s ) {
			return !empty( trim( $s ) ) && !str_starts_with( trim( $s ), '--' );
		} );

		$this->assertCount( 2, $nonCommentStatements );

		$values = array_values( $nonCommentStatements );
		// First should be the multi-line INSERT
		$this->assertStringContainsString( 'INSERT INTO users', $values[0] );
		$this->assertStringContainsString( "O''Brien", $values[0] );
		$this->assertStringContainsString( "who\\'s passionate", $values[0] );

		// Second should be the UPDATE
		$this->assertStringContainsString( 'UPDATE settings', $values[1] );
		$this->assertStringContainsString( "It''s a wonderful", $values[1] );
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
