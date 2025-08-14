<?php

namespace Tests\Mvc\Controllers;

use Neuron\Mvc\Application;
use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use PHPUnit\Framework\TestCase;

class CacheInitializationTest extends TestCase
{
	private string $TempCacheDir;
	
	protected function setUp(): void
	{
		parent::setUp();
		
		// Clear registry keys we'll use
		$Registry = Registry::getInstance();
		$Registry->set( 'ViewCache', null );
		$Registry->set( 'Settings', null );
		$Registry->set( 'Base.Path', null );
		
		// Create temp cache directory
		$this->TempCacheDir = sys_get_temp_dir() . '/test_cache_' . uniqid();
		mkdir( $this->TempCacheDir );
	}
	
	protected function tearDown(): void
	{
		parent::tearDown();
		
		// Clean up temp directory
		if( is_dir( $this->TempCacheDir ) )
		{
			$this->deleteDirectory( $this->TempCacheDir );
		}
		
		// Clear registry keys we used
		$Registry = Registry::getInstance();
		$Registry->set( 'ViewCache', null );
		$Registry->set( 'Settings', null );
		$Registry->set( 'Base.Path', null );
	}
	
	public function testInitializeViewCacheCreatesInstanceWhenNotInRegistry()
	{
		// Setup settings in registry
		$Settings = $this->createMock( \Neuron\Data\Setting\Source\ISettingSource::class );
		$Settings->method( 'get' )
			->willReturnMap( [
				['cache', 'enabled', 'true'],
				['cache', 'storage', 'file'],
				['cache', 'path', 'cache/views'],
				['cache', 'ttl', '3600'],
				['cache.views', 'html', 'true'],
				['cache', 'gc_probability', '0.01'],
				['cache', 'gc_divisor', '100']
			] );
		
		$Registry = Registry::getInstance();
		$Registry->set( 'Settings', $Settings );
		$Registry->set( 'Base.Path', $this->TempCacheDir );
		
		// Create test controller
		$Controller = new CacheInitTestController();
		
		// Test initialization
		$ViewCache = $Controller->testInitializeViewCache();
		
		$this->assertInstanceOf( ViewCache::class, $ViewCache );
		$this->assertTrue( $ViewCache->isEnabled() );
		
		// Verify it was added to registry
		$this->assertSame( $ViewCache, $Registry->get( 'ViewCache' ) );
	}
	
	public function testInitializeViewCacheReturnsExistingInstanceFromRegistry()
	{
		// Add existing cache to registry
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ExistingCache = new ViewCache( $Storage, true, 7200 );
		
		$Registry = Registry::getInstance();
		$Registry->set( 'ViewCache', $ExistingCache );
		
		// Create test controller
		$Controller = new CacheInitTestController( new Application() );
		
		// Test initialization returns existing instance
		$ViewCache = $Controller->testInitializeViewCache();
		
		$this->assertSame( $ExistingCache, $ViewCache );
	}
	
	public function testInitializeViewCacheReturnsNullWhenCacheDisabled()
	{
		// Setup settings with cache disabled
		$Settings = $this->createMock( \Neuron\Data\Setting\Source\ISettingSource::class );
		$Settings->method( 'get' )
			->willReturnMap( [
				['cache', 'enabled', 'false'],
				['cache', 'storage', 'file'],
				['cache', 'path', 'cache/views'],
				['cache', 'ttl', '3600']
			] );
		
		$Registry = Registry::getInstance();
		$Registry->set( 'Settings', $Settings );
		
		// Create test controller
		$Controller = new CacheInitTestController( new Application() );
		
		// Test initialization returns null
		$ViewCache = $Controller->testInitializeViewCache();
		
		$this->assertNull( $ViewCache );
		$this->assertNull( $Registry->get( 'ViewCache' ) );
	}
	
	public function testHasViewCacheReturnsTrueWhenCacheExists()
	{
		// Setup cache with test data
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		
		// Generate expected cache key
		$CacheKey = $ViewCache->generateKey( 'CacheInitTestController', 'testpage', ['id' => 123] );
		$ViewCache->set( $CacheKey, 'cached content' );
		
		$Registry = Registry::getInstance();
		$Registry->set( 'ViewCache', $ViewCache );
		
		// Create test controller
		$Controller = new CacheInitTestController();
		
		// Test hasViewCache
		$this->assertTrue( $Controller->testHasViewCache( 'testpage', ['id' => 123] ) );
		$this->assertFalse( $Controller->testHasViewCache( 'testpage', ['id' => 456] ) );
	}
	
	public function testHasViewCacheInitializesCacheWhenNotInRegistry()
	{
		// Setup settings
		$Settings = $this->createMock( \Neuron\Data\Setting\Source\ISettingSource::class );
		$Settings->method( 'get' )
			->willReturnMap( [
				['cache', 'enabled', 'true'],
				['cache', 'storage', 'file'],
				['cache', 'path', 'cache/views'],
				['cache', 'ttl', '3600'],
				['cache.views', 'html', 'true'],
				['cache', 'gc_probability', '0'],
				['cache', 'gc_divisor', '100']
			] );
		
		$Registry = Registry::getInstance();
		$Registry->set( 'Settings', $Settings );
		$Registry->set( 'Base.Path', $this->TempCacheDir );
		
		// Verify cache not in registry initially
		$this->assertNull( $Registry->get( 'ViewCache' ) );
		
		// Create test controller
		$Controller = new CacheInitTestController();
		
		// Call hasViewCache should initialize cache
		$Result = $Controller->testHasViewCache( 'testpage', [] );
		
		// Cache should now be in registry
		$this->assertInstanceOf( ViewCache::class, $Registry->get( 'ViewCache' ) );
		$this->assertFalse( $Result ); // No cache exists yet
	}
	
	public function testGetViewCacheReturnsContentWhenCacheExists()
	{
		// Setup cache with test data
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		
		// Generate expected cache key and set content
		$CacheKey = $ViewCache->generateKey( 'CacheInitTestController', 'testpage', ['id' => 123] );
		$TestContent = '<html><body>Cached content for id 123</body></html>';
		$ViewCache->set( $CacheKey, $TestContent );
		
		$Registry = Registry::getInstance();
		$Registry->set( 'ViewCache', $ViewCache );
		
		// Create test controller
		$Controller = new CacheInitTestController( new Application() );
		
		// Test getViewCache
		$CachedContent = $Controller->testGetViewCache( 'testpage', ['id' => 123] );
		$this->assertEquals( $TestContent, $CachedContent );
		
		// Test with different data (should return null)
		$this->assertNull( $Controller->testGetViewCache( 'testpage', ['id' => 456] ) );
	}
	
	public function testGetViewCacheInitializesCacheWhenNotInRegistry()
	{
		// Setup settings
		$Settings = $this->createMock( \Neuron\Data\Setting\Source\ISettingSource::class );
		$Settings->method( 'get' )
			->willReturnMap( [
				['cache', 'enabled', 'true'],
				['cache', 'storage', 'file'],
				['cache', 'path', 'cache/views'],
				['cache', 'ttl', '3600'],
				['cache.views', 'html', 'true'],
				['cache', 'gc_probability', '0'],
				['cache', 'gc_divisor', '100']
			] );
		
		$Registry = Registry::getInstance();
		$Registry->set( 'Settings', $Settings );
		$Registry->set( 'Base.Path', $this->TempCacheDir );
		
		// Verify cache not in registry initially
		$this->assertNull( $Registry->get( 'ViewCache' ) );
		
		// Create test controller
		$Controller = new CacheInitTestController();
		
		// Call getViewCache should initialize cache
		$Result = $Controller->testGetViewCache( 'testpage', [] );
		
		// Cache should now be in registry
		$this->assertInstanceOf( ViewCache::class, $Registry->get( 'ViewCache' ) );
		$this->assertNull( $Result ); // No cached content exists yet
	}
	
	public function testGetControllerNameReturnsCorrectName()
	{
		$Controller = new CacheInitTestController( new Application() );
		$this->assertEquals( 'CacheInitTestController', $Controller->testGetControllerName() );
		
		$Controller2 = new CacheInitAnotherTestController( new Application() );
		$this->assertEquals( 'CacheInitAnotherTestController', $Controller2->testGetControllerName() );
	}
	
	private function deleteDirectory( string $Dir ): void
	{
		if( !is_dir( $Dir ) )
		{
			return;
		}
		
		$Files = array_diff( scandir( $Dir ), ['.', '..'] );
		foreach( $Files as $File )
		{
			$Path = $Dir . '/' . $File;
			if( is_dir( $Path ) )
			{
				$this->deleteDirectory( $Path );
			}
			else
			{
				unlink( $Path );
			}
		}
		rmdir( $Dir );
	}
}

// Test controller class that exposes protected methods for testing
class CacheInitTestController extends Base
{
	public function testInitializeViewCache(): ?ViewCache
	{
		return $this->initializeViewCache();
	}
	
	public function testHasViewCache( string $Page, array $Data = [] ): bool
	{
		return $this->hasViewCache( $Page, $Data );
	}
	
	public function testGetViewCache( string $Page, array $Data = [] ): ?string
	{
		return $this->getViewCache( $Page, $Data );
	}
	
	public function testGetControllerName(): string
	{
		return $this->getControllerName();
	}
}

// Another test controller to verify getControllerName works with different classes
class CacheInitAnotherTestController extends Base
{
	public function testGetControllerName(): string
	{
		return $this->getControllerName();
	}
}
