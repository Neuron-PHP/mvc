<?php
namespace Mvc\Cache;

use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

class CacheIntegrationTest extends TestCase
{
	private $Root;
	
	protected function setUp(): void
	{
		$this->Root = vfsStream::setup( 'test' );
	}
	
	public function testCompleteViewCacheWorkflow()
	{
		// Create cache storage
		$CachePath = vfsStream::url( 'test/cache' );
		$Storage = new FileCacheStorage( $CachePath );
		
		// Create view cache with 2 second TTL
		$Cache = new ViewCache( $Storage, true, 2 );
		
		// Generate cache key
		$Key = $Cache->generateKey( 'TestController', 'index', [ 'user' => 'john' ] );
		
		// Store content
		$Content = '<html><body>Hello John!</body></html>';
		$this->assertTrue( $Cache->set( $Key, $Content ) );
		
		// Retrieve content
		$Retrieved = $Cache->get( $Key );
		$this->assertEquals( $Content, $Retrieved );
		
		// Check existence
		$this->assertTrue( $Cache->exists( $Key ) );
		
		// Wait for expiration
		sleep( 3 );
		
		// Check expired
		$this->assertNull( $Cache->get( $Key ) );
		$this->assertFalse( $Cache->exists( $Key ) );
	}
	
	public function testCacheDisabling()
	{
		$Storage = new FileCacheStorage( vfsStream::url( 'test/cache' ) );
		$Cache = new ViewCache( $Storage, false );
		
		$Key = 'test_key';
		$Content = 'test content';
		
		// When disabled, set returns false
		$this->assertFalse( $Cache->set( $Key, $Content ) );
		
		// When disabled, get returns null
		$this->assertNull( $Cache->get( $Key ) );
		
		// When disabled, exists returns false
		$this->assertFalse( $Cache->exists( $Key ) );
	}
	
	public function testCacheKeyGeneration()
	{
		$Storage = new FileCacheStorage( vfsStream::url( 'test/cache' ) );
		$Cache = new ViewCache( $Storage );
		
		// Same data produces same key
		$Key1 = $Cache->generateKey( 'Home', 'index', [ 'a' => 1, 'b' => 2 ] );
		$Key2 = $Cache->generateKey( 'Home', 'index', [ 'b' => 2, 'a' => 1 ] );
		$this->assertEquals( $Key1, $Key2 );
		
		// Different data produces different key
		$Key3 = $Cache->generateKey( 'Home', 'index', [ 'a' => 1, 'b' => 3 ] );
		$this->assertNotEquals( $Key1, $Key3 );
		
		// Different controller produces different key
		$Key4 = $Cache->generateKey( 'About', 'index', [ 'a' => 1, 'b' => 2 ] );
		$this->assertNotEquals( $Key1, $Key4 );
		
		// Different view produces different key
		$Key5 = $Cache->generateKey( 'Home', 'contact', [ 'a' => 1, 'b' => 2 ] );
		$this->assertNotEquals( $Key1, $Key5 );
	}
}