<?php

namespace Tests\Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Mvc\Database\DataImporter;
use PHPUnit\Framework\TestCase;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * Test that CSV import properly uses metadata and filesystem abstraction
 */
class CsvImportMetadataTest extends TestCase
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
	 * Test that glob() is called on filesystem abstraction, not PHP's global function
	 */
	public function testUsesFilesystemGlob(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		// Create mock filesystem
		$mockFs = $this->createMock( IFileSystem::class );

		// Expect isDir check
		$mockFs->expects( $this->once() )
			->method( 'isDir' )
			->with( '/test/dir' )
			->willReturn( true );

		// Expect file existence checks - metadata file and each CSV file
		$mockFs->expects( $this->exactly( 3 ) ) // metadata + 2 CSV files
			->method( 'fileExists' )
			->willReturnMap( [
				['/test/dir/export_metadata.json', false],
				['/test/dir/users.csv', true],
				['/test/dir/products.csv', true]
			] );

		// Expect glob to be called on filesystem abstraction
		$mockFs->expects( $this->once() )
			->method( 'glob' )
			->with( '/test/dir/*.csv' )
			->willReturn( [
				'/test/dir/users.csv',
				'/test/dir/products.csv'
			] );

		// Mock CSV file reads
		$mockFs->expects( $this->exactly( 2 ) )
			->method( 'readFile' )
			->willReturnMap( [
				['/test/dir/users.csv', "id,name\n1,John"],
				['/test/dir/products.csv', "id,product\n1,Widget"]
			] );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log', [], $mockFs );

		// This should use $mockFs->glob() not PHP's glob()
		$result = $importer->importFromCsvDirectory( '/test/dir' );

		$this->assertTrue( $result );
	}

	/**
	 * Test that metadata is used to order CSV files
	 */
	public function testUsesMetadataForOrdering(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		// Create mock filesystem
		$mockFs = $this->createMock( IFileSystem::class );

		// Mock directory check
		$mockFs->expects( $this->once() )
			->method( 'isDir' )
			->with( '/test/dir' )
			->willReturn( true );

		// Mock file existence checks - metadata and CSV files
		$mockFs->expects( $this->exactly( 3 ) ) // metadata + 2 CSV files
			->method( 'fileExists' )
			->willReturnMap( [
				['/test/dir/export_metadata.json', true],
				['/test/dir/products.csv', true],
				['/test/dir/users.csv', true]
			] );

		// Mock metadata content with specific table order
		$metadata = [
			'exported_at' => '2024-01-01 10:00:00',
			'database_type' => 'mysql',
			'tables' => ['products.csv', 'users.csv'] // Products should be imported first
		];

		// Mock file reads
		$callCount = 0;
		$mockFs->expects( $this->exactly( 3 ) ) // metadata + 2 CSV files
			->method( 'readFile' )
			->willReturnCallback( function( $path ) use ( &$callCount, $metadata ) {
				$callCount++;
				if( $path === '/test/dir/export_metadata.json' )
				{
					return json_encode( $metadata );
				}
				elseif( $path === '/test/dir/products.csv' )
				{
					return "id,product\n1,Widget";
				}
				elseif( $path === '/test/dir/users.csv' )
				{
					return "id,name\n1,John";
				}
				return false;
			} );

		// Return files in different order than metadata specifies
		$mockFs->expects( $this->once() )
			->method( 'glob' )
			->with( '/test/dir/*.csv' )
			->willReturn( [
				'/test/dir/users.csv',  // Users returned first
				'/test/dir/products.csv' // Products returned second
			] );

		// Track import order
		$importOrder = [];
		$mockAdapter->expects( $this->atLeastOnce() )
			->method( 'execute' )
			->willReturnCallback( function( $sql ) use ( &$importOrder ) {
				if( strpos( $sql, 'INSERT INTO' ) === 0 )
				{
					// Extract table name from INSERT statement
					preg_match( '/INSERT INTO [`"]?(\w+)[`"]?/', $sql, $matches );
					if( isset( $matches[1] ) )
					{
						$importOrder[] = $matches[1];
					}
				}
				return 1;
			} );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log', [], $mockFs );

		$result = $importer->importFromCsvDirectory( '/test/dir' );

		$this->assertTrue( $result );
		// Verify tables were imported in metadata order (products first, then users)
		$this->assertEquals( ['products', 'users'], $importOrder );
	}

	/**
	 * Test that warnings are generated for missing files mentioned in metadata
	 */
	public function testWarnsAboutMissingFiles(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		// Create mock filesystem
		$mockFs = $this->createMock( IFileSystem::class );

		$mockFs->expects( $this->once() )
			->method( 'isDir' )
			->willReturn( true );

		// Only users.csv exists, the other files are missing
		$mockFs->expects( $this->exactly( 2 ) ) // metadata + 1 CSV file
			->method( 'fileExists' )
			->willReturnMap( [
				['/test/dir/export_metadata.json', true],
				['/test/dir/users.csv', true]
			] );

		// Metadata mentions 3 tables
		$metadata = [
			'exported_at' => '2024-01-01 10:00:00',
			'database_type' => 'mysql',
			'tables' => ['users.csv', 'products.csv', 'orders.csv']
		];

		$mockFs->expects( $this->exactly( 2 ) )
			->method( 'readFile' )
			->willReturnMap( [
				['/test/dir/export_metadata.json', json_encode( $metadata )],
				['/test/dir/users.csv', "id,name\n1,John"]
			] );

		// But only 1 CSV file exists (orders.csv and products.csv are missing)
		$mockFs->expects( $this->once() )
			->method( 'glob' )
			->willReturn( ['/test/dir/users.csv'] );

		// Expect at least one execute
		$mockAdapter->expects( $this->atLeastOnce() )
			->method( 'execute' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log', [], $mockFs );

		$result = $importer->importFromCsvDirectory( '/test/dir' );

		// Get errors/warnings
		$errors = $importer->getErrors();

		// Should have warnings about missing files
		$this->assertCount( 2, $errors );
		$this->assertStringContainsString( "products.csv", $errors[0] );
		$this->assertStringContainsString( "orders.csv", $errors[1] );
		$this->assertStringContainsString( "Warning:", $errors[0] );
	}

	/**
	 * Test that import works without metadata (backward compatibility)
	 */
	public function testWorksWithoutMetadata(): void
	{
		$mockAdapter = $this->createMockAdapter();
		$this->mockAdapterFactory( $mockAdapter );

		// Create mock filesystem
		$mockFs = $this->createMock( IFileSystem::class );

		$mockFs->expects( $this->once() )
			->method( 'isDir' )
			->willReturn( true );

		// No metadata file, but CSV files exist
		$mockFs->expects( $this->exactly( 3 ) ) // metadata check + 2 CSV files
			->method( 'fileExists' )
			->willReturnMap( [
				['/test/dir/export_metadata.json', false],
				['/test/dir/users.csv', true],
				['/test/dir/products.csv', true]
			] );

		// Return CSV files
		$mockFs->expects( $this->once() )
			->method( 'glob' )
			->willReturn( [
				'/test/dir/users.csv',
				'/test/dir/products.csv'
			] );

		$mockFs->expects( $this->exactly( 2 ) )
			->method( 'readFile' )
			->willReturnMap( [
				['/test/dir/users.csv', "id,name\n1,John"],
				['/test/dir/products.csv', "id,product\n1,Widget"]
			] );

		$mockAdapter->expects( $this->atLeastOnce() )
			->method( 'execute' );

		$config = $this->createMockConfig();
		$importer = new DataImporter( $config, 'testing', 'phinx_log', [], $mockFs );

		$result = $importer->importFromCsvDirectory( '/test/dir' );

		$this->assertTrue( $result );
		// Should have no errors/warnings when metadata is absent
		$this->assertEmpty( $importer->getErrors() );
	}

	// Helper methods

	private function createMockAdapter(): AdapterInterface
	{
		// Create mock PDO for string escaping
		$mockPdo = $this->createMock( \PDO::class );
		$mockPdo->method( 'quote' )->willReturnCallback( function( $value ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		} );

		// Use getMockBuilder to add additional methods that might not be on interface
		$mockAdapter = $this->getMockBuilder( AdapterInterface::class )
			->onlyMethods( get_class_methods( AdapterInterface::class ) )
			->addMethods( ['hasTransaction', 'getConnection'] )
			->getMock();

		$mockAdapter->method( 'connect' );
		$mockAdapter->method( 'getAdapterType' )->willReturn( 'mysql' );
		$mockAdapter->method( 'hasTable' )->willReturn( true );
		$mockAdapter->method( 'execute' )->willReturn( 1 );
		$mockAdapter->method( 'beginTransaction' );
		$mockAdapter->method( 'commitTransaction' );
		$mockAdapter->method( 'hasTransaction' )->willReturn( false );
		$mockAdapter->method( 'getConnection' )->willReturn( $mockPdo );
		$mockAdapter->method( 'fetchRow' )->willReturn( ['count' => 0] ); // For prepareTableForImport

		return $mockAdapter;
	}

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
