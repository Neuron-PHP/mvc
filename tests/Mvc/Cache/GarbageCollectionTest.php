<?php
namespace Mvc\Cache;

use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

class GarbageCollectionTest extends TestCase
{
	private $Root;
	
	protected function setUp(): void
	{
		$this->Root = vfsStream::setup( 'cache' );
	}
	
	public function testGcRemovesExpiredEntries()
	{
		$Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		
		// Write entries with different TTLs
		$Storage->write( 'keep1', 'content', 3600 ); // Keep (1 hour)
		$Storage->write( 'keep2', 'content', 3600 ); // Keep (1 hour)
		$Storage->write( 'expire1', 'content', 1 );  // Expire after 1 second
		$Storage->write( 'expire2', 'content', 1 );  // Expire after 1 second
		
		// Verify all exist
		$this->assertTrue( $Storage->exists( 'keep1' ) );
		$this->assertTrue( $Storage->exists( 'keep2' ) );
		$this->assertTrue( $Storage->exists( 'expire1' ) );
		$this->assertTrue( $Storage->exists( 'expire2' ) );
		
		// Wait for expiration
		sleep( 2 );
		
		// Run garbage collection
		$Count = $Storage->gc();
		
		// Should have removed 2 expired entries
		$this->assertEquals( 2, $Count );
		
		// Verify correct entries remain
		$this->assertTrue( $Storage->exists( 'keep1' ) );
		$this->assertTrue( $Storage->exists( 'keep2' ) );
		$this->assertFalse( $Storage->exists( 'expire1' ) );
		$this->assertFalse( $Storage->exists( 'expire2' ) );
	}
	
	public function testGcReturnsZeroWhenNoExpiredEntries()
	{
		$Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		
		// Write entries that won't expire
		$Storage->write( 'test1', 'content', 3600 );
		$Storage->write( 'test2', 'content', 3600 );
		
		// Run garbage collection
		$Count = $Storage->gc();
		
		// No entries should be removed
		$this->assertEquals( 0, $Count );
		$this->assertTrue( $Storage->exists( 'test1' ) );
		$this->assertTrue( $Storage->exists( 'test2' ) );
	}
	
	public function testViewCacheGc()
	{
		$Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$Cache = new ViewCache( $Storage );
		
		// Add expired entry
		$Cache->set( 'expire', 'content', 1 );
		sleep( 2 );
		
		// Run GC through ViewCache
		$Count = $Cache->gc();
		
		$this->assertEquals( 1, $Count );
		$this->assertFalse( $Cache->exists( 'expire' ) );
	}
	
	public function testAutomaticGcWithAlwaysRunProbability()
	{
		$Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		
		// Create config with 100% GC probability
		$Config = new CacheConfig([
			'gc_probability' => 1.0, // Always run
			'gc_divisor' => 100
		]);
		
		$Cache = new ViewCache( $Storage, true, 3600, $Config );
		
		// Add expired entry
		$Cache->set( 'expire', 'content', 1 );
		sleep( 2 );
		
		// This should trigger GC
		$Cache->set( 'new', 'content' );
		
		// Expired entry should be gone
		$this->assertFalse( $Cache->exists( 'expire' ) );
		$this->assertTrue( $Cache->exists( 'new' ) );
	}
	
	public function testAutomaticGcDisabled()
	{
		$Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		
		// Create config with 0% GC probability (disabled)
		$Config = new CacheConfig([
			'gc_probability' => 0.0,
			'gc_divisor' => 100
		]);
		
		$Cache = new ViewCache( $Storage, true, 3600, $Config );
		
		// Add expired entry
		$Cache->set( 'expire', 'content', 1 );
		sleep( 2 );
		
		// This should NOT trigger GC
		$Cache->set( 'new', 'content' );
		
		// Expired entry should still be there (not cleaned)
		// Note: exists() will return false because it checks expiration
		// But the files are still on disk
		$this->assertFalse( $Cache->exists( 'expire' ) );
		$this->assertTrue( $Cache->exists( 'new' ) );
		
		// Manually run GC to verify it would have cleaned 1 entry
		$Count = $Cache->gc();
		$this->assertEquals( 1, $Count );
	}
	
	public function testGcProbabilityDefaults()
	{
		$Config = new CacheConfig([]);
		
		// Should have default values
		$this->assertEquals( 0.01, $Config->getGcProbability() ); // 1%
		$this->assertEquals( 100, $Config->getGcDivisor() );
	}
	
	public function testGcHandlesEmptyCache()
	{
		$Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		
		// Run GC on empty cache
		$Count = $Storage->gc();
		
		$this->assertEquals( 0, $Count );
	}
	
	public function testGcRemovesEmptySubdirectories()
	{
		// Note: This test may be skipped due to vfsStream limitations
		if( strpos( vfsStream::url( 'cache' ), 'vfs://' ) === 0 )
		{
			$this->markTestSkipped( 'Subdirectory removal test skipped due to vfsStream limitations' );
			return;
		}
		
		$Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		
		// Write and let expire
		$Storage->write( 'test', 'content', 1 );
		sleep( 2 );
		
		// Run GC
		$Storage->gc();
		
		// Check subdirectories are cleaned up
		$Items = scandir( vfsStream::url( 'cache' ) );
		$NonDotItems = array_filter( $Items, function( $Item ) {
			return $Item !== '.' && $Item !== '..';
		});
		
		$this->assertCount( 0, $NonDotItems, 'Empty subdirectories should be removed' );
	}
}