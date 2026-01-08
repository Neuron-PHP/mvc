<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use Neuron\Core\System\IFileSystem;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that identifier quoting prevents SQL injection through malicious column/table names
 */
class IdentifierQuotingSecurityTest extends TestCase
{
	private $tempDir;
	private $originalFactory;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temp directory for test files
		$this->tempDir = sys_get_temp_dir() . '/identifier_security_test_' . uniqid();
		mkdir( $this->tempDir, 0777, true );

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
	 * Test that malicious column names with backticks are properly escaped
	 */
	public function testMaliciousColumnNamesWithBackticks(): void
	{
		// Track executed SQL
		$executedSql = [];

		// Create mock PDO for escaping
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		// Create mock adapter that captures SQL
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection', 'hasTransaction'] )
			->getMock();
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 0] ); // For any COUNT queries

		// Capture executed SQL
		$mockAdapter->method( 'execute' )->willReturnCallback(
			function( $sql ) use ( &$executedSql ) {
				$executedSql[] = $sql;
				return 1;
			}
		);

		$this->mockAdapterFactory( $mockAdapter );

		// Create JSON file with malicious column names
		$maliciousData = [
			'data' => [
				'users' => [
					[
						'id' => 1,
						'name`; DROP TABLE users; --' => 'Injection Attempt 1',
						'email``test' => 'test@example.com',
						'role`admin`' => 'user'
					],
					[
						'id' => 2,
						'name`; DROP TABLE users; --' => 'Injection Attempt 2',
						'email``test' => 'test2@example.com',
						'role`admin`' => 'guest'
					]
				]
			]
		];

		$jsonFile = $this->tempDir . '/malicious.json';
		file_put_contents( $jsonFile, json_encode( $maliciousData ) );

		$config = $this->createMockConfig();

		// Create filesystem mock
		$mockFs = $this->createMock( IFileSystem::class );
		$mockFs->method( 'fileExists' )->with( $jsonFile )->willReturn( true );
		$mockFs->method( 'readFile' )->with( $jsonFile )->willReturn( json_encode( $maliciousData ) );

		$importer = new DataImporter( $config, 'testing', 'phinx_log', [
			'format' => DataImporter::FORMAT_JSON
		] );

		// Inject filesystem mock via reflection
		$reflector = new \ReflectionClass( $importer );
		$fsProp = $reflector->getProperty( 'fs' );
		$fsProp->setAccessible( true );
		$fsProp->setValue( $importer, $mockFs );

		// Import the file
		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result );

		// Verify SQL was executed
		$this->assertNotEmpty( $executedSql );

		// Find the INSERT statement (skip any SET FOREIGN_KEY_CHECKS statements)
		$sql = '';
		foreach( $executedSql as $stmt )
		{
			if( stripos( $stmt, 'INSERT INTO' ) !== false )
			{
				$sql = $stmt;
				break;
			}
		}

		// Debug: If no INSERT found, show what was captured
		if( empty( $sql ) )
		{
			echo "\nDebug: JSON data: " . json_encode( $maliciousData ) . "\n";
			echo "Debug: Number of SQL statements: " . count( $executedSql ) . "\n";
			foreach( $executedSql as $i => $stmt )
			{
				echo "Debug: SQL[$i]: " . $stmt . "\n";
			}
			$this->fail( 'No INSERT statement found. Captured SQL: ' . implode( "\n", $executedSql ) );
		}

		// For MySQL, backticks should be doubled for escaping
		$this->assertStringContainsString( '`name``; DROP TABLE users; --`', $sql );
		$this->assertStringContainsString( '`email````test`', $sql );
		$this->assertStringContainsString( '`role``admin```', $sql );

		// Ensure the dangerous strings are contained within properly escaped column names
		// The semicolon and DROP TABLE are part of the column identifier itself, safely escaped within backticks
		// Verify the entire malicious string is within the backtick-quoted identifier
		$this->assertMatchesRegularExpression( '/`name``; DROP TABLE users; --`/', $sql );

		// Verify that backticks within identifiers are properly doubled (escaped)
		// This prevents breaking out of the identifier context
		$this->assertStringNotContainsString( '` `', $sql ); // No unescaped backticks that could break out

		// The values should be properly quoted strings, not executable commands
		$valuesSection = substr( $sql, strpos( $sql, 'VALUES' ) );
		$this->assertStringContainsString( "'Injection Attempt", $valuesSection ); // Values are string literals
	}

	/**
	 * Test PostgreSQL-style injection attempts with double quotes
	 */
	public function testPostgreSQLIdentifierEscaping(): void
	{
		$executedSql = [];

		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		// Create mock adapter for PostgreSQL
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection', 'hasTransaction'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'pgsql' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 0] ); // For any COUNT queries
		$mockAdapter->method( 'execute' )->willReturnCallback(
			function( $sql ) use ( &$executedSql ) {
				$executedSql[] = $sql;
				return 1;
			}
		);

		$this->mockAdapterFactory( $mockAdapter );

		// Create JSON with PostgreSQL-style injection attempts
		$maliciousData = [
			'data' => [
				'products' => [
					[
						'id' => 1,
						'name"; DROP TABLE products; --' => 'Injection',
						'price"test' => 99.99
					]
				]
			]
		];

		$jsonFile = $this->tempDir . '/pgsql_malicious.json';
		file_put_contents( $jsonFile, json_encode( $maliciousData ) );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log', [
			'format' => DataImporter::FORMAT_JSON
		] );

		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result );

		// Find the INSERT statement
		$sql = '';
		foreach( $executedSql as $stmt )
		{
			if( stripos( $stmt, 'INSERT INTO' ) !== false )
			{
				$sql = $stmt;
				break;
			}
		}
		$this->assertNotEmpty( $sql, 'No INSERT statement found' );

		// Check PostgreSQL uses double quotes and escapes them properly
		$this->assertStringContainsString( '"name""; DROP TABLE products; --"', $sql );
		$this->assertStringContainsString( '"price""test"', $sql );
	}

	/**
	 * Test SQL Server bracket escaping
	 */
	public function testSQLServerBracketEscaping(): void
	{
		$executedSql = [];

		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['getConnection', 'hasTransaction'] )
			->getMock();

		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'sqlsrv' );
		$mockAdapter->method( 'getOption' )->willReturn( 'test_db' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 0] ); // For any COUNT queries
		$mockAdapter->method( 'execute' )->willReturnCallback(
			function( $sql ) use ( &$executedSql ) {
				$executedSql[] = $sql;
				return 1;
			}
		);

		$this->mockAdapterFactory( $mockAdapter );

		// Create JSON with SQL Server bracket injection attempts
		$maliciousData = [
			'data' => [
				'orders' => [
					[
						'id' => 1,
						'order]; DROP TABLE orders; --' => 'Malicious',
						'status]test' => 'pending'
					]
				]
			]
		];

		$jsonFile = $this->tempDir . '/sqlsrv_malicious.json';
		file_put_contents( $jsonFile, json_encode( $maliciousData ) );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log', [
			'format' => DataImporter::FORMAT_JSON
		] );

		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result );

		// Find the INSERT statement
		$sql = '';
		foreach( $executedSql as $stmt )
		{
			if( stripos( $stmt, 'INSERT INTO' ) !== false )
			{
				$sql = $stmt;
				break;
			}
		}
		$this->assertNotEmpty( $sql, 'No INSERT statement found' );

		// Check SQL Server uses brackets and escapes them properly
		$this->assertStringContainsString( '[order]]; DROP TABLE orders; --]', $sql );
		$this->assertStringContainsString( '[status]]test]', $sql );
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

	private function recursiveRemoveDir( $dir ): void
	{
		if( !is_dir( $dir ) ) return;

		$objects = scandir( $dir );
		foreach( $objects as $object )
		{
			if( $object != "." && $object != ".." )
			{
				if( is_dir( $dir . "/" . $object ) )
				{
					$this->recursiveRemoveDir( $dir . "/" . $object );
				}
				else
				{
					unlink( $dir . "/" . $object );
				}
			}
		}
		rmdir( $dir );
	}
}
