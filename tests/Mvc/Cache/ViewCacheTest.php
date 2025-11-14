<?php
namespace Mvc\Cache;

use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

class ViewCacheTest extends TestCase
{
	private $Root;
	private ViewCache $Cache;
	private FileCacheStorage $Storage;

	protected function setUp(): void
	{
		$this->Root = vfsStream::setup( 'cache' );
		$this->Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$this->Cache = new ViewCache( $this->Storage );
	}

	public function testCacheSetAndGet()
	{
		$Key = 'test_key';
		$Content = 'Test content';
		
		$this->assertTrue( $this->Cache->set( $Key, $Content ) );
		$this->assertEquals( $Content, $this->Cache->get( $Key ) );
	}

	public function testCacheExists()
	{
		$Key = 'test_exists';
		$Content = 'Test content';
		
		$this->assertFalse( $this->Cache->exists( $Key ) );
		
		$this->Cache->set( $Key, $Content );
		
		$this->assertTrue( $this->Cache->exists( $Key ) );
	}

	public function testCacheDelete()
	{
		$Key = 'test_delete';
		$Content = 'Test content';
		
		$this->Cache->set( $Key, $Content );
		$this->assertTrue( $this->Cache->exists( $Key ) );
		
		$this->assertTrue( $this->Cache->delete( $Key ) );
		$this->assertFalse( $this->Cache->exists( $Key ) );
	}

	public function testCacheClear()
	{
		// Use real filesystem for this test due to vfsStream limitations with recursive directory clearing
		$TempDir = sys_get_temp_dir() . '/neuron_test_cache_' . uniqid();
		mkdir( $TempDir, 0777, true );

		try
		{
			$RealStorage = new FileCacheStorage( $TempDir );
			$RealCache = new ViewCache( $RealStorage );

			$RealCache->set( 'key1', 'content1' );
			$RealCache->set( 'key2', 'content2' );

			$this->assertTrue( $RealCache->exists( 'key1' ) );
			$this->assertTrue( $RealCache->exists( 'key2' ) );

			$this->assertTrue( $RealCache->clear() );

			$this->assertFalse( $RealCache->exists( 'key1' ) );
			$this->assertFalse( $RealCache->exists( 'key2' ) );
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

	public function testGenerateKey()
	{
		$Controller = 'TestController';
		$View = 'index';
		$Data = [ 'id' => 1, 'name' => 'test' ];
		
		$Key1 = $this->Cache->generateKey( $Controller, $View, $Data );
		$Key2 = $this->Cache->generateKey( $Controller, $View, $Data );
		
		$this->assertEquals( $Key1, $Key2 );
		
		$Data['id'] = 2;
		$Key3 = $this->Cache->generateKey( $Controller, $View, $Data );
		
		$this->assertNotEquals( $Key1, $Key3 );
	}

	public function testCacheDisabled()
	{
		$DisabledCache = new ViewCache( $this->Storage, false );
		
		$Key = 'test_disabled';
		$Content = 'Test content';
		
		$this->assertFalse( $DisabledCache->set( $Key, $Content ) );
		$this->assertNull( $DisabledCache->get( $Key ) );
		$this->assertFalse( $DisabledCache->exists( $Key ) );
	}

	public function testCacheTtl()
	{
		$ShortLivedCache = new ViewCache( $this->Storage, true, 1 );
		
		$Key = 'test_ttl';
		$Content = 'Test content';
		
		$ShortLivedCache->set( $Key, $Content, 1 );
		$this->assertEquals( $Content, $ShortLivedCache->get( $Key ) );
		
		sleep( 2 );
		
		$this->assertNull( $ShortLivedCache->get( $Key ) );
		$this->assertFalse( $ShortLivedCache->exists( $Key ) );
	}

	public function testEnableDisableCache()
	{
		$Key = 'test_toggle';
		$Content = 'Test content';
		
		$this->assertTrue( $this->Cache->isEnabled() );
		
		$this->Cache->setEnabled( false );
		$this->assertFalse( $this->Cache->isEnabled() );
		
		$this->assertFalse( $this->Cache->set( $Key, $Content ) );
		
		$this->Cache->setEnabled( true );
		$this->assertTrue( $this->Cache->isEnabled() );
		
		$this->assertTrue( $this->Cache->set( $Key, $Content ) );
		$this->assertEquals( $Content, $this->Cache->get( $Key ) );
	}
}