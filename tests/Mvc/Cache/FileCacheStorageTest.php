<?php
namespace Mvc\Cache;

use Neuron\Core\System\FrozenClock;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

/**
 * Tests FileCacheStorage using vfsStream for isolation and speed.
 * Uses FrozenClock for instant time-based tests without sleep().
 * Note: Some operations like clear() don't work with vfsStream due to its limitations.
 * See FileCacheStorageRealTest for tests using real filesystem.
 */
class FileCacheStorageTest extends TestCase
{
	private $Root;
	private FileCacheStorage $Storage;
	private FrozenClock $Clock;

	protected function setUp(): void
	{
		$this->Root = vfsStream::setup( 'cache' );
		$this->Clock = new FrozenClock( 1000000 ); // Start at a fixed time
		$this->Storage = new FileCacheStorage( vfsStream::url( 'cache' ), null, $this->Clock );
	}

	public function testWriteAndRead()
	{
		$Key = 'test_key';
		$Content = 'Test content';
		$Ttl = 3600;
		
		$this->assertTrue( $this->Storage->write( $Key, $Content, $Ttl ) );
		$this->assertEquals( $Content, $this->Storage->read( $Key ) );
	}

	public function testReadNonExistent()
	{
		$this->assertNull( $this->Storage->read( 'non_existent_key' ) );
	}

	public function testExists()
	{
		$Key = 'test_exists';
		$Content = 'Test content';
		
		$this->assertFalse( $this->Storage->exists( $Key ) );
		
		$this->Storage->write( $Key, $Content, 3600 );
		
		$this->assertTrue( $this->Storage->exists( $Key ) );
	}

	public function testDelete()
	{
		$Key = 'test_delete';
		$Content = 'Test content';
		
		$this->Storage->write( $Key, $Content, 3600 );
		$this->assertTrue( $this->Storage->exists( $Key ) );
		
		$this->assertTrue( $this->Storage->delete( $Key ) );
		$this->assertFalse( $this->Storage->exists( $Key ) );
		$this->assertNull( $this->Storage->read( $Key ) );
	}

	public function testDeleteNonExistent()
	{
		$this->assertTrue( $this->Storage->delete( 'non_existent_key' ) );
	}

	public function testIsExpired()
	{
		$Key = 'test_expired';
		$Content = 'Test content';

		$this->Storage->write( $Key, $Content, 1 );
		$this->assertFalse( $this->Storage->isExpired( $Key ) );

		// Advance time by 2 seconds (instant, no actual sleeping)
		$this->Clock->advance( 2 );

		$this->assertTrue( $this->Storage->isExpired( $Key ) );
		$this->assertNull( $this->Storage->read( $Key ) );
	}

	public function testClear()
	{
		// Use real filesystem for this test due to vfsStream limitations with recursive directory clearing
		$TempDir = sys_get_temp_dir() . '/neuron_test_file_cache_' . uniqid();
		mkdir( $TempDir, 0777, true );

		try
		{
			$RealStorage = new FileCacheStorage( $TempDir );

			$RealStorage->write( 'key1', 'content1', 3600 );
			$RealStorage->write( 'key2', 'content2', 3600 );
			$RealStorage->write( 'key3', 'content3', 3600 );

			$this->assertTrue( $RealStorage->exists( 'key1' ) );
			$this->assertTrue( $RealStorage->exists( 'key2' ) );
			$this->assertTrue( $RealStorage->exists( 'key3' ) );

			$this->assertTrue( $RealStorage->clear() );

			$this->assertFalse( $RealStorage->exists( 'key1' ) );
			$this->assertFalse( $RealStorage->exists( 'key2' ) );
			$this->assertFalse( $RealStorage->exists( 'key3' ) );
		}
		finally
		{
			// Clean up temp directory
			if( is_dir( $TempDir ) )
			{
				$this->recursiveRemoveDirectory( $TempDir );
			}
		}
	}

	/**
	 * Helper method to recursively remove a directory
	 */
	private function recursiveRemoveDirectory( string $Dir ): void
	{
		if( !is_dir( $Dir ) )
		{
			return;
		}

		$Items = array_diff( scandir( $Dir ), [ '.', '..' ] );

		foreach( $Items as $Item )
		{
			$Path = $Dir . DIRECTORY_SEPARATOR . $Item;

			if( is_dir( $Path ) )
			{
				$this->recursiveRemoveDirectory( $Path );
			}
			else
			{
				unlink( $Path );
			}
		}

		rmdir( $Dir );
	}

	public function testSubdirectoryCreation()
	{
		$Keys = [];
		
		for( $i = 0; $i < 10; $i++ )
		{
			$Key = "test_key_$i";
			$Keys[] = $Key;
			$this->Storage->write( $Key, "Content $i", 3600 );
		}
		
		foreach( $Keys as $Key )
		{
			$this->assertTrue( $this->Storage->exists( $Key ) );
		}
		
		$this->assertTrue( count( $this->Root->getChildren() ) > 0 );
	}

	public function testInvalidCacheDirectory()
	{
		$this->expectException( CacheException::class );
		$this->expectExceptionMessage( 'Unable to create cache directory' );
		
		$InvalidPath = vfsStream::url( 'cache/invalid' );
		chmod( vfsStream::url( 'cache' ), 0000 );
		
		new FileCacheStorage( $InvalidPath );
	}
	
	public function testGarbageCollection()
	{
		// Create some cache entries with different TTLs
		$this->Storage->write( 'keep1', 'content1', 3600 ); // Keep for 1 hour
		$this->Storage->write( 'keep2', 'content2', 3600 ); // Keep for 1 hour
		$this->Storage->write( 'expire1', 'content3', 1 ); // Expire in 1 second
		$this->Storage->write( 'expire2', 'content4', 1 ); // Expire in 1 second

		// All should exist initially
		$this->assertTrue( $this->Storage->exists( 'keep1' ) );
		$this->assertTrue( $this->Storage->exists( 'keep2' ) );
		$this->assertTrue( $this->Storage->exists( 'expire1' ) );
		$this->assertTrue( $this->Storage->exists( 'expire2' ) );

		// Advance time by 2 seconds (instant, no actual sleeping)
		$this->Clock->advance( 2 );

		// Run garbage collection
		$Removed = $this->Storage->gc();
		$this->assertEquals( 2, $Removed );

		// Check that only non-expired entries remain
		$this->assertTrue( $this->Storage->exists( 'keep1' ) );
		$this->assertTrue( $this->Storage->exists( 'keep2' ) );
		$this->assertFalse( $this->Storage->exists( 'expire1' ) );
		$this->assertFalse( $this->Storage->exists( 'expire2' ) );
	}
	
	public function testGarbageCollectionOnEmptyCache()
	{
		$Removed = $this->Storage->gc();
		$this->assertEquals( 0, $Removed );
	}
	
	public function testReadExpiredEntry()
	{
		$Key = 'test_read_expired';
		$this->Storage->write( $Key, 'content', 1 );

		// Advance time by 2 seconds (instant, no actual sleeping)
		$this->Clock->advance( 2 );

		// Reading expired entry should return null and delete it
		$this->assertNull( $this->Storage->read( $Key ) );
		$this->assertFalse( $this->Storage->exists( $Key ) );
	}
	
	public function testClearWithNonExistentDirectory()
	{
		// Create storage with non-existent base path
		$Storage = new FileCacheStorage( vfsStream::url( 'cache/newdir' ) );
		
		// Clear should still return true even if directory doesn't fully exist
		$this->assertTrue( $Storage->clear() );
	}
	
	public function testIsExpiredWithMissingMetaFile()
	{
		$Key = 'test_missing_meta';
		
		// Manually create cache file without meta file
		$Hash = md5( $Key );
		$SubDir = substr( $Hash, 0, 2 );
		$Dir = vfsStream::newDirectory( $SubDir )->at( $this->Root );
		vfsStream::newFile( $Hash . '.cache' )
			->at( $Dir )
			->withContent( 'content' );
		
		// Should be considered expired if meta file is missing
		$this->assertTrue( $this->Storage->isExpired( $Key ) );
	}
	
	public function testIsExpiredWithCorruptedMetaFile()
	{
		$Key = 'test_corrupted_meta';
		
		// Manually create cache and corrupted meta file
		$Hash = md5( $Key );
		$SubDir = substr( $Hash, 0, 2 );
		$Dir = vfsStream::newDirectory( $SubDir )->at( $this->Root );
		vfsStream::newFile( $Hash . '.cache' )
			->at( $Dir )
			->withContent( 'content' );
		vfsStream::newFile( $Hash . '.meta' )
			->at( $Dir )
			->withContent( 'not valid json' );
		
		// Should be considered expired if meta file is corrupted
		$this->assertTrue( $this->Storage->isExpired( $Key ) );
	}
	
	public function testIsExpiredWithIncompleteMetaData()
	{
		$Key = 'test_incomplete_meta';
		
		// Manually create cache and meta file without expires field
		$Hash = md5( $Key );
		$SubDir = substr( $Hash, 0, 2 );
		$Dir = vfsStream::newDirectory( $SubDir )->at( $this->Root );
		vfsStream::newFile( $Hash . '.cache' )
			->at( $Dir )
			->withContent( 'content' );
		vfsStream::newFile( $Hash . '.meta' )
			->at( $Dir )
			->withContent( json_encode( [ 'created' => $this->Clock->time() ] ) );
		
		// Should be considered expired if meta data is incomplete
		$this->assertTrue( $this->Storage->isExpired( $Key ) );
	}
}