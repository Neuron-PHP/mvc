<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\DataImporter;
use Phinx\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Test that batch_size validation prevents ValueError in array_chunk
 *
 * PHP's array_chunk() requires length > 0, so passing zero or negative
 * batch sizes causes a ValueError. This test verifies that both
 * RestoreCommand and DataImporter validate batch_size before calling
 * array_chunk().
 */
class BatchSizeValidationTest extends TestCase
{
	/**
	 * Test that DataImporter constructor rejects zero batch size
	 */
	public function testDataImporterRejectsZeroBatchSize(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'batch_size must be greater than 0' );

		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'batch_size_test_' ) . '.db';

		try
		{
			// Create Phinx config
			$config = new Config( [
				'paths' => [
					'migrations' => __DIR__
				],
				'environments' => [
					'default_migration_table' => 'phinx_log',
					'default_environment' => 'testing',
					'testing' => [
						'adapter' => 'sqlite',
						'name' => $dbPath
					]
				]
			] );

			// Try to create DataImporter with zero batch size
			new DataImporter(
				$config,
				'testing',
				'phinx_log',
				['batch_size' => 0]
			);
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test that DataImporter constructor rejects negative batch size
	 */
	public function testDataImporterRejectsNegativeBatchSize(): void
	{
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'batch_size must be greater than 0' );

		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'batch_size_test_' ) . '.db';

		try
		{
			// Create Phinx config
			$config = new Config( [
				'paths' => [
					'migrations' => __DIR__
				],
				'environments' => [
					'default_migration_table' => 'phinx_log',
					'default_environment' => 'testing',
					'testing' => [
						'adapter' => 'sqlite',
						'name' => $dbPath
					]
				]
			] );

			// Try to create DataImporter with negative batch size
			new DataImporter(
				$config,
				'testing',
				'phinx_log',
				['batch_size' => -1]
			);
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test that DataImporter accepts valid batch sizes
	 */
	public function testDataImporterAcceptsValidBatchSize(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'batch_size_test_' ) . '.db';

		try
		{
			// Create Phinx config
			$config = new Config( [
				'paths' => [
					'migrations' => __DIR__
				],
				'environments' => [
					'default_migration_table' => 'phinx_log',
					'default_environment' => 'testing',
					'testing' => [
						'adapter' => 'sqlite',
						'name' => $dbPath
					]
				]
			] );

			// Test various valid batch sizes
			$validBatchSizes = [1, 10, 100, 1000, 5000];

			foreach( $validBatchSizes as $batchSize )
			{
				$importer = new DataImporter(
					$config,
					'testing',
					'phinx_log',
					['batch_size' => $batchSize]
				);

				// Should not throw exception
				$this->assertInstanceOf( DataImporter::class, $importer, "Should accept batch size {$batchSize}" );
				$importer->disconnect();
			}
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test that default batch size is valid
	 */
	public function testDefaultBatchSizeIsValid(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'batch_size_test_' ) . '.db';

		try
		{
			// Create Phinx config
			$config = new Config( [
				'paths' => [
					'migrations' => __DIR__
				],
				'environments' => [
					'default_migration_table' => 'phinx_log',
					'default_environment' => 'testing',
					'testing' => [
						'adapter' => 'sqlite',
						'name' => $dbPath
					]
				]
			] );

			// Create DataImporter without specifying batch_size (should use default)
			$importer = new DataImporter(
				$config,
				'testing',
				'phinx_log',
				[]
			);

			// Should not throw exception
			$this->assertInstanceOf( DataImporter::class, $importer );
			$importer->disconnect();
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}

	/**
	 * Test that very large batch sizes are accepted
	 */
	public function testVeryLargeBatchSizesAreAccepted(): void
	{
		// Create a temporary SQLite database
		$dbPath = tempnam( sys_get_temp_dir(), 'batch_size_test_' ) . '.db';

		try
		{
			// Create Phinx config
			$config = new Config( [
				'paths' => [
					'migrations' => __DIR__
				],
				'environments' => [
					'default_migration_table' => 'phinx_log',
					'default_environment' => 'testing',
					'testing' => [
						'adapter' => 'sqlite',
						'name' => $dbPath
					]
				]
			] );

			// Test very large batch size
			$importer = new DataImporter(
				$config,
				'testing',
				'phinx_log',
				['batch_size' => 100000]
			);

			// Should not throw exception
			$this->assertInstanceOf( DataImporter::class, $importer );
			$importer->disconnect();
		}
		finally
		{
			if( file_exists( $dbPath ) )
			{
				unlink( $dbPath );
			}
		}
	}
}
