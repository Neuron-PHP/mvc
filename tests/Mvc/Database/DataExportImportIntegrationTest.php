<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataExporter;
use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;

/**
 * Comprehensive end-to-end integration tests for DataExporter/DataImporter
 *
 * These tests perform actual database operations with real SQLite databases
 * to verify that data can be exported and imported correctly across all formats,
 * preserving data integrity, relationships, and special cases.
 */
class DataExportImportIntegrationTest extends TestCase
{
	private $sourceDbPath;
	private $targetDbPath;
	private $tempDir;
	private $sourceAdapter;
	private $targetAdapter;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temporary directory for test files
		$this->tempDir = sys_get_temp_dir() . '/export_import_integration_' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		// Create temporary database paths
		$this->sourceDbPath = tempnam( sys_get_temp_dir(), 'source_db_' );
		$this->targetDbPath = tempnam( sys_get_temp_dir(), 'target_db_' );

		// Reset AdapterFactory
		$this->resetAdapterFactory();

		// Create and populate source database
		$this->createSourceDatabase();
	}

	protected function tearDown(): void
	{
		// Disconnect adapters
		if( isset( $this->sourceAdapter ) )
		{
			$this->sourceAdapter->disconnect();
		}
		if( isset( $this->targetAdapter ) )
		{
			$this->targetAdapter->disconnect();
		}

		// Clean up temporary files
		if( isset( $this->tempDir ) && is_dir( $this->tempDir ) )
		{
			$this->recursiveRemoveDir( $this->tempDir );
		}

		// Clean up database files
		if( isset( $this->sourceDbPath ) && file_exists( $this->sourceDbPath ) )
		{
			unlink( $this->sourceDbPath );
		}
		if( isset( $this->targetDbPath ) && file_exists( $this->targetDbPath ) )
		{
			unlink( $this->targetDbPath );
		}

		// Reset AdapterFactory
		$this->resetAdapterFactory();

		parent::tearDown();
	}

	/**
	 * Test full export -> import roundtrip with SQL format
	 */
	public function testFullRoundtripWithSqlFormat(): void
	{
		// Export from source database to SQL file with schema
		$exporter = $this->createExporter( 'sql', ['include_schema' => true] );
		$sqlFile = $this->tempDir . '/export.sql';
		$exporter->exportToFile( $sqlFile );
		$exporter->disconnect();

		$this->assertFileExists( $sqlFile, 'SQL export file should exist' );

		// Import into target database (no need for schema as SQL includes it)
		// Disable transactions since SQL file may contain its own transaction handling
		$importer = $this->createImporter( 'sql', ['skip_schema' => true, 'use_transaction' => false] );
		$result = $importer->importFromFile( $sqlFile );
		$this->assertTrue( $result, 'SQL import should succeed' );

		// Verify data integrity
		$this->verifyDataIntegrity();

		$importer->disconnect();
	}

	/**
	 * Test full export -> import roundtrip with JSON format
	 */
	public function testFullRoundtripWithJsonFormat(): void
	{
		// Export to JSON
		$exporter = $this->createExporter( 'json' );
		$jsonFile = $this->tempDir . '/export.json';
		$exporter->exportToFile( $jsonFile );
		$exporter->disconnect();

		$this->assertFileExists( $jsonFile, 'JSON export file should exist' );

		// Verify JSON structure has 'data' wrapper
		$jsonContent = json_decode( file_get_contents( $jsonFile ), true );
		$this->assertArrayHasKey( 'data', $jsonContent, 'JSON should have data wrapper' );

		// Import into target database
		$importer = $this->createImporter( 'json' );
		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result, 'JSON import should succeed' );

		// Verify data integrity
		$this->verifyDataIntegrity();

		$importer->disconnect();
	}

	/**
	 * Test full export -> import roundtrip with YAML format
	 */
	public function testFullRoundtripWithYamlFormat(): void
	{
		// Export to YAML
		$exporter = $this->createExporter( 'yaml' );
		$yamlFile = $this->tempDir . '/export.yaml';
		$exporter->exportToFile( $yamlFile );
		$exporter->disconnect();

		$this->assertFileExists( $yamlFile, 'YAML export file should exist' );

		// Verify YAML structure
		$yamlContent = \Symfony\Component\Yaml\Yaml::parse( file_get_contents( $yamlFile ) );
		$this->assertArrayHasKey( 'data', $yamlContent, 'YAML should have data wrapper' );

		// Import into target database
		$importer = $this->createImporter( 'yaml' );
		$result = $importer->importFromFile( $yamlFile );
		$this->assertTrue( $result, 'YAML import should succeed' );

		// Verify data integrity
		$this->verifyDataIntegrity();

		$importer->disconnect();
	}

	/**
	 * Test full export -> import roundtrip with CSV format
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testFullRoundtripWithCsvFormat(): void
	{
		// Export to CSV directory
		$exporter = $this->createExporter( 'csv' );
		$csvDir = $this->tempDir . '/csv_export';
		mkdir( $csvDir );
		$exporter->exportCsvToDirectory( $csvDir );
		$exporter->disconnect();

		// Verify CSV files created
		$this->assertFileExists( $csvDir . '/users.csv', 'users.csv should exist' );
		$this->assertFileExists( $csvDir . '/posts.csv', 'posts.csv should exist' );
		$this->assertFileExists( $csvDir . '/categories.csv', 'categories.csv should exist' );

		// Import from CSV directory
		$importer = $this->createImporter( 'csv' );
		$result = $importer->importFromCsvDirectory( $csvDir );
		$this->assertTrue( $result, 'CSV import should succeed' );

		// Verify data integrity
		$this->verifyDataIntegrity();

		$importer->disconnect();
	}

	/**
	 * Test that foreign key relationships are preserved
	 */
	public function testForeignKeyRelationshipsPreserved(): void
	{
		// Export to JSON (easier to inspect)
		$exporter = $this->createExporter( 'json' );
		$jsonFile = $this->tempDir . '/export_fk.json';
		$exporter->exportToFile( $jsonFile );
		$exporter->disconnect();

		// Import with foreign key checks enabled
		$importer = $this->createImporter( 'json', [
			'disable_foreign_keys' => true,
			'use_transaction' => true
		] );
		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result, 'Import with FK handling should succeed' );

		// Verify referential integrity - posts should reference valid users and categories
		$this->targetAdapter = $this->getTargetAdapter();
		$posts = $this->targetAdapter->fetchAll( 'SELECT * FROM posts' );
		$users = $this->targetAdapter->fetchAll( 'SELECT * FROM users' );
		$categories = $this->targetAdapter->fetchAll( 'SELECT * FROM categories' );

		$userIds = array_column( $users, 'id' );
		$categoryIds = array_column( $categories, 'id' );

		foreach( $posts as $post )
		{
			$this->assertContains( $post['user_id'], $userIds, 'Post user_id should reference valid user' );
			$this->assertContains( $post['category_id'], $categoryIds, 'Post category_id should reference valid category' );
		}

		$importer->disconnect();
	}

	/**
	 * Test compressed file roundtrip
	 */
	public function testCompressedRoundtrip(): void
	{
		// Export to compressed JSON
		$exporter = $this->createExporter( 'json', ['compress' => true] );
		$jsonFile = $this->tempDir . '/export.json';
		$actualFile = $exporter->exportToFile( $jsonFile );
		$exporter->disconnect();

		// Should have .gz extension
		$this->assertStringEndsWith( '.gz', $actualFile, 'Compressed file should have .gz extension' );
		$this->assertFileExists( $actualFile );

		// Verify it's actually compressed
		$compressedData = file_get_contents( $actualFile );
		$uncompressedData = gzdecode( $compressedData );
		$this->assertNotFalse( $uncompressedData, 'Should be valid gzip data' );

		// Import from compressed file
		$importer = $this->createImporter( 'json' );
		$result = $importer->importFromFile( $actualFile );
		$this->assertTrue( $result, 'Import from compressed file should succeed' );

		// Verify data integrity
		$this->verifyDataIntegrity();

		$importer->disconnect();
	}

	/**
	 * Test that various data types are preserved across formats
	 */
	public function testDataTypesPreservedAcrossFormats(): void
	{
		// Test with each format
		$formats = ['sql', 'json', 'yaml'];

		foreach( $formats as $format )
		{
			// Reset target database for each format
			if( file_exists( $this->targetDbPath ) )
			{
				unlink( $this->targetDbPath );
			}
			$this->targetDbPath = tempnam( sys_get_temp_dir(), 'target_db_' );

			// Export (SQL format needs include_schema)
			$exportOptions = $format === 'sql' ? ['include_schema' => true] : [];
			$exporter = $this->createExporter( $format, $exportOptions );
			$exportFile = $this->tempDir . "/export_datatypes.{$format}";
			$exporter->exportToFile( $exportFile );
			$exporter->disconnect();

			// Import (SQL format skips schema creation as it's in the SQL file)
			$importOptions = $format === 'sql' ? ['skip_schema' => true, 'use_transaction' => false] : [];
			$importer = $this->createImporter( $format, $importOptions );
			$result = $importer->importFromFile( $exportFile );
			$this->assertTrue( $result, "{$format} import should succeed" );

			// Verify special data types
			$this->targetAdapter = $this->getTargetAdapter();
			$users = $this->targetAdapter->fetchAll( 'SELECT * FROM users ORDER BY id' );

			// User 1: Normal data
			$this->assertEquals( 'Alice', $users[0]['name'] );
			$this->assertEquals( 'alice@example.com', $users[0]['email'] );
			$this->assertEquals( 1, $users[0]['active'] );

			// User 2: Empty string vs NULL
			$this->assertEquals( 'Bob', $users[1]['name'] );
			$this->assertEquals( '', $users[1]['bio'], "Empty string should be preserved in {$format}" );
			$this->assertNull( $users[1]['website'], "NULL should be preserved in {$format}" );

			// User 3: String "NULL" should NOT be converted to NULL
			$this->assertEquals( 'Charlie', $users[2]['name'] );
			$this->assertEquals( 'NULL', $users[2]['bio'], "String 'NULL' should not be converted to NULL in {$format}" );

			// User 4: Leading zeros in string
			$this->assertEquals( '00123', $users[3]['zip_code'], "Leading zeros should be preserved in {$format}" );

			$importer->disconnect();
		}
	}

	/**
	 * Test table filtering during export/import
	 */
	public function testTableFilteringDuringRoundtrip(): void
	{
		// Export only users and categories (exclude posts)
		$exporter = $this->createExporter( 'json', ['tables' => ['users', 'categories']] );
		$jsonFile = $this->tempDir . '/export_filtered.json';
		$exporter->exportToFile( $jsonFile );
		$exporter->disconnect();

		// Verify JSON only contains specified tables
		$jsonContent = json_decode( file_get_contents( $jsonFile ), true );
		$this->assertArrayHasKey( 'users', $jsonContent['data'] );
		$this->assertArrayHasKey( 'categories', $jsonContent['data'] );
		$this->assertArrayNotHasKey( 'posts', $jsonContent['data'], 'Excluded table should not be in export' );

		// Import
		$importer = $this->createImporter( 'json' );
		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result );

		// Verify only specified tables have data
		$this->targetAdapter = $this->getTargetAdapter();
		$usersCount = $this->targetAdapter->fetchRow( 'SELECT COUNT(*) as count FROM users' )['count'];
		$categoriesCount = $this->targetAdapter->fetchRow( 'SELECT COUNT(*) as count FROM categories' )['count'];
		$postsCount = $this->targetAdapter->fetchRow( 'SELECT COUNT(*) as count FROM posts' )['count'];

		$this->assertGreaterThan( 0, $usersCount, 'Users table should have data' );
		$this->assertGreaterThan( 0, $categoriesCount, 'Categories table should have data' );
		$this->assertEquals( 0, $postsCount, 'Posts table should be empty' );

		$importer->disconnect();
	}

	/**
	 * Test clear_tables option
	 */
	public function testClearTablesOptionIntegration(): void
	{
		// Export only users and categories (no foreign keys for simpler test)
		$exporter = $this->createExporter( 'json', ['tables' => ['users', 'categories']] );
		$jsonFile = $this->tempDir . '/export_clear.json';
		$exporter->exportToFile( $jsonFile );
		$exporter->disconnect();

		// First import
		$importer = $this->createImporter( 'json' );
		$result = $importer->importFromFile( $jsonFile );
		$this->assertTrue( $result );
		$importer->disconnect();

		// Verify initial data
		$this->targetAdapter = $this->getTargetAdapter();
		$initialUsersCount = $this->targetAdapter->fetchRow( 'SELECT COUNT(*) as count FROM users' )['count'];
		$initialCategoriesCount = $this->targetAdapter->fetchRow( 'SELECT COUNT(*) as count FROM categories' )['count'];
		$this->assertGreaterThan( 0, $initialUsersCount );
		$this->assertGreaterThan( 0, $initialCategoriesCount );
		$this->targetAdapter->disconnect();

		// Import again with clear_tables=true
		$importer2 = $this->createImporter( 'json', ['clear_tables' => true] );
		$result2 = $importer2->importFromFile( $jsonFile );
		$this->assertTrue( $result2 );

		// Reconnect adapter to check final counts
		$this->targetAdapter = $this->getTargetAdapter();
		$finalUsersCount = $this->targetAdapter->fetchRow( 'SELECT COUNT(*) as count FROM users' )['count'];
		$finalCategoriesCount = $this->targetAdapter->fetchRow( 'SELECT COUNT(*) as count FROM categories' )['count'];

		// Counts should be the same (not doubled)
		$this->assertEquals( $initialUsersCount, $finalUsersCount, 'Users should be cleared before import' );
		$this->assertEquals( $initialCategoriesCount, $finalCategoriesCount, 'Categories should be cleared before import' );

		$importer2->disconnect();
	}

	// ========================================
	// Helper Methods
	// ========================================

	/**
	 * Create and populate source database with comprehensive test data
	 */
	private function createSourceDatabase(): void
	{
		$pdo = new \PDO( 'sqlite:' . $this->sourceDbPath );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

		// Create phinx_log table (required by Phinx)
		$pdo->exec( "
			CREATE TABLE phinx_log (
				version INTEGER PRIMARY KEY
			)
		" );

		// Create users table
		$pdo->exec( "
			CREATE TABLE users (
				id INTEGER PRIMARY KEY,
				name TEXT NOT NULL,
				email TEXT,
				bio TEXT,
				website TEXT,
				zip_code TEXT,
				active INTEGER DEFAULT 1
			)
		" );

		// Create categories table
		$pdo->exec( "
			CREATE TABLE categories (
				id INTEGER PRIMARY KEY,
				name TEXT NOT NULL,
				description TEXT
			)
		" );

		// Create posts table with foreign keys
		$pdo->exec( "
			CREATE TABLE posts (
				id INTEGER PRIMARY KEY,
				user_id INTEGER NOT NULL,
				category_id INTEGER NOT NULL,
				title TEXT NOT NULL,
				content TEXT,
				published INTEGER DEFAULT 0,
				FOREIGN KEY (user_id) REFERENCES users(id),
				FOREIGN KEY (category_id) REFERENCES categories(id)
			)
		" );

		// Insert test data with edge cases
		// User 1: Normal data
		$pdo->exec( "INSERT INTO users (id, name, email, bio, website, zip_code, active)
			VALUES (1, 'Alice', 'alice@example.com', 'Software developer', 'https://alice.dev', '12345', 1)" );

		// User 2: Empty string vs NULL
		$pdo->exec( "INSERT INTO users (id, name, email, bio, website, zip_code, active)
			VALUES (2, 'Bob', 'bob@example.com', '', NULL, '67890', 0)" );

		// User 3: String "NULL" should NOT be converted to NULL
		$pdo->exec( "INSERT INTO users (id, name, email, bio, website, zip_code, active)
			VALUES (3, 'Charlie', 'charlie@example.com', 'NULL', 'https://charlie.com', '11111', 1)" );

		// User 4: Leading zeros in string
		$pdo->exec( "INSERT INTO users (id, name, email, bio, website, zip_code, active)
			VALUES (4, 'Diana', 'diana@example.com', 'Designer', NULL, '00123', 1)" );

		// Categories
		$pdo->exec( "INSERT INTO categories (id, name, description) VALUES (1, 'Technology', 'Tech articles')" );
		$pdo->exec( "INSERT INTO categories (id, name, description) VALUES (2, 'Science', 'Science posts')" );
		$pdo->exec( "INSERT INTO categories (id, name, description) VALUES (3, 'Art', NULL)" );

		// Posts
		$pdo->exec( "INSERT INTO posts (id, user_id, category_id, title, content, published)
			VALUES (1, 1, 1, 'First Post', 'Content here', 1)" );
		$pdo->exec( "INSERT INTO posts (id, user_id, category_id, title, content, published)
			VALUES (2, 1, 2, 'Second Post', 'More content', 1)" );
		$pdo->exec( "INSERT INTO posts (id, user_id, category_id, title, content, published)
			VALUES (3, 2, 1, 'Bob''s Post', 'With escaped quote', 0)" );
		$pdo->exec( "INSERT INTO posts (id, user_id, category_id, title, content, published)
			VALUES (4, 3, 3, 'Art Post', '', 1)" );

		unset( $pdo );
	}

	/**
	 * Create DataExporter for source database
	 */
	private function createExporter( string $format, array $options = [] ): DataExporter
	{
		$config = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->sourceDbPath,
					'suffix' => ''  // Don't append .sqlite3 to database name
				]
			]
		] );

		$defaultOptions = [
			'format' => $format,
			'exclude' => ['phinx_log']
		];

		return new DataExporter(
			$config,
			'testing',
			'phinx_log',
			array_merge( $defaultOptions, $options )
		);
	}

	/**
	 * Create DataImporter for target database
	 */
	private function createImporter( string $format, array $options = [] ): DataImporter
	{
		// Ensure target database has the schema (unless skip_schema is set)
		if( !isset( $options['skip_schema'] ) || !$options['skip_schema'] )
		{
			$this->createTargetSchema();
		}

		// Remove skip_schema from options as it's not a DataImporter option
		unset( $options['skip_schema'] );

		$config = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'default_migration_table' => 'phinx_log',
				'default_environment' => 'testing',
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->targetDbPath,
					'suffix' => ''  // Don't append .sqlite3 to database name
				]
			]
		] );

		$defaultOptions = [
			'format' => $format,
			'exclude' => ['phinx_log']
		];

		return new DataImporter(
			$config,
			'testing',
			'phinx_log',
			array_merge( $defaultOptions, $options )
		);
	}

	/**
	 * Create schema in target database (same as source)
	 */
	private function createTargetSchema(): void
	{
		$pdo = new \PDO( 'sqlite:' . $this->targetDbPath );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

		// Create phinx_log table first (required by Phinx)
		$pdo->exec( "
			CREATE TABLE IF NOT EXISTS phinx_log (
				version INTEGER PRIMARY KEY
			)
		" );

		// Create same tables as source
		$pdo->exec( "
			CREATE TABLE IF NOT EXISTS users (
				id INTEGER PRIMARY KEY,
				name TEXT NOT NULL,
				email TEXT,
				bio TEXT,
				website TEXT,
				zip_code TEXT,
				active INTEGER DEFAULT 1
			)
		" );

		$pdo->exec( "
			CREATE TABLE IF NOT EXISTS categories (
				id INTEGER PRIMARY KEY,
				name TEXT NOT NULL,
				description TEXT
			)
		" );

		$pdo->exec( "
			CREATE TABLE IF NOT EXISTS posts (
				id INTEGER PRIMARY KEY,
				user_id INTEGER NOT NULL,
				category_id INTEGER NOT NULL,
				title TEXT NOT NULL,
				content TEXT,
				published INTEGER DEFAULT 0,
				FOREIGN KEY (user_id) REFERENCES users(id),
				FOREIGN KEY (category_id) REFERENCES categories(id)
			)
		" );

		// Explicitly close PDO connection to flush changes
		$pdo = null;
	}

	/**
	 * Verify data integrity between source and target databases
	 */
	private function verifyDataIntegrity(): void
	{
		$this->sourceAdapter = $this->getSourceAdapter();
		$this->targetAdapter = $this->getTargetAdapter();

		// Verify users table
		$sourceUsers = $this->sourceAdapter->fetchAll( 'SELECT * FROM users ORDER BY id' );
		$targetUsers = $this->targetAdapter->fetchAll( 'SELECT * FROM users ORDER BY id' );

		$this->assertCount( count( $sourceUsers ), $targetUsers, 'Users count should match' );
		foreach( $sourceUsers as $index => $sourceUser )
		{
			$targetUser = $targetUsers[$index];
			$this->assertEquals( $sourceUser['id'], $targetUser['id'], "User {$index} ID should match" );
			$this->assertEquals( $sourceUser['name'], $targetUser['name'], "User {$index} name should match" );
			$this->assertEquals( $sourceUser['email'], $targetUser['email'], "User {$index} email should match" );
			$this->assertEquals( $sourceUser['bio'], $targetUser['bio'], "User {$index} bio should match" );
			$this->assertEquals( $sourceUser['website'], $targetUser['website'], "User {$index} website should match" );
			$this->assertEquals( $sourceUser['zip_code'], $targetUser['zip_code'], "User {$index} zip_code should match" );
			$this->assertEquals( $sourceUser['active'], $targetUser['active'], "User {$index} active should match" );
		}

		// Verify categories table
		$sourceCategories = $this->sourceAdapter->fetchAll( 'SELECT * FROM categories ORDER BY id' );
		$targetCategories = $this->targetAdapter->fetchAll( 'SELECT * FROM categories ORDER BY id' );

		$this->assertCount( count( $sourceCategories ), $targetCategories, 'Categories count should match' );
		foreach( $sourceCategories as $index => $sourceCategory )
		{
			$targetCategory = $targetCategories[$index];
			$this->assertEquals( $sourceCategory['id'], $targetCategory['id'], "Category {$index} ID should match" );
			$this->assertEquals( $sourceCategory['name'], $targetCategory['name'], "Category {$index} name should match" );
			$this->assertEquals( $sourceCategory['description'], $targetCategory['description'], "Category {$index} description should match" );
		}

		// Verify posts table
		$sourcePosts = $this->sourceAdapter->fetchAll( 'SELECT * FROM posts ORDER BY id' );
		$targetPosts = $this->targetAdapter->fetchAll( 'SELECT * FROM posts ORDER BY id' );

		$this->assertCount( count( $sourcePosts ), $targetPosts, 'Posts count should match' );
		foreach( $sourcePosts as $index => $sourcePost )
		{
			$targetPost = $targetPosts[$index];
			$this->assertEquals( $sourcePost['id'], $targetPost['id'], "Post {$index} ID should match" );
			$this->assertEquals( $sourcePost['user_id'], $targetPost['user_id'], "Post {$index} user_id should match" );
			$this->assertEquals( $sourcePost['category_id'], $targetPost['category_id'], "Post {$index} category_id should match" );
			$this->assertEquals( $sourcePost['title'], $targetPost['title'], "Post {$index} title should match" );
			$this->assertEquals( $sourcePost['content'], $targetPost['content'], "Post {$index} content should match" );
			$this->assertEquals( $sourcePost['published'], $targetPost['published'], "Post {$index} published should match" );
		}
	}

	/**
	 * Get adapter for source database
	 */
	private function getSourceAdapter()
	{
		$config = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->sourceDbPath,
					'suffix' => ''  // Don't append .sqlite3 to database name
				]
			]
		] );

		$options = $config->getEnvironment( 'testing' );
		$adapter = AdapterFactory::instance()->getAdapter( $options['adapter'], $options );
		$adapter->connect();

		return $adapter;
	}

	/**
	 * Get adapter for target database
	 */
	private function getTargetAdapter()
	{
		$config = new Config( [
			'paths' => ['migrations' => __DIR__],
			'environments' => [
				'testing' => [
					'adapter' => 'sqlite',
					'name' => $this->targetDbPath,
					'suffix' => ''  // Don't append .sqlite3 to database name
				]
			]
		] );

		$options = $config->getEnvironment( 'testing' );
		$adapter = AdapterFactory::instance()->getAdapter( $options['adapter'], $options );
		$adapter->connect();

		return $adapter;
	}

	/**
	 * Reset AdapterFactory to ensure clean state
	 */
	private function resetAdapterFactory(): void
	{
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$instanceProperty->setValue( null, null );
	}

	/**
	 * Recursively remove directory
	 */
	private function recursiveRemoveDir( string $dir ): void
	{
		if( !is_dir( $dir ) )
		{
			return;
		}

		$files = array_diff( scandir( $dir ), ['.', '..'] );
		foreach( $files as $file )
		{
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->recursiveRemoveDir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
