<?php

namespace Tests\Mvc\Views;

use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Mvc\Views\Base;
use Neuron\Mvc\Views\CacheableView;
use Neuron\Patterns\Registry;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * Test class that uses the CacheableView trait
 */
class TestableView extends Base
{
	use CacheableView;

	public function render( array $data ): string
	{
		$cacheKey = $this->getCacheKey( $data );

		if( $cacheKey && $cachedContent = $this->getCachedContent( $cacheKey ) )
		{
			return $cachedContent;
		}

		$content = "rendered:" . json_encode( $data );

		if( $cacheKey )
		{
			$this->setCachedContent( $cacheKey, $content );
		}

		return $content;
	}

	// Expose protected methods for testing
	public function exposedGetCacheKey( array $data ): string
	{
		return $this->getCacheKey( $data );
	}

	public function exposedGetCachedContent( string $key ): ?string
	{
		return $this->getCachedContent( $key );
	}

	public function exposedSetCachedContent( string $key, string $content ): void
	{
		$this->setCachedContent( $key, $content );
	}

	public function exposedIsCacheEnabled(): bool
	{
		return $this->isCacheEnabled();
	}
}

class CacheableViewTest extends TestCase
{
	private $vfs;

	protected function setUp(): void
	{
		parent::setUp();

		// Set up virtual filesystem
		$this->vfs = vfsStream::setup( 'cache' );

		// Clear registry
		Registry::getInstance()->set( 'ViewCache', null );
		Registry::getInstance()->set( 'Settings', null );
	}

	protected function tearDown(): void
	{
		// Clean up registry
		Registry::getInstance()->set( 'ViewCache', null );
		Registry::getInstance()->set( 'Settings', null );

		parent::tearDown();
	}

	public function testGetCacheKeyWithCacheDisabled(): void
	{
		$view = new TestableView();
		$view->setController( 'test' );
		$view->setPage( 'index' );
		$view->setCacheEnabled( false );

		$key = $view->exposedGetCacheKey( ['id' => 1] );

		$this->assertEquals( '', $key );
	}

	public function testGetCacheKeyWithoutCache(): void
	{
		$view = new TestableView();
		$view->setController( 'test' );
		$view->setPage( 'index' );

		// No cache in registry
		$key = $view->exposedGetCacheKey( ['id' => 1] );

		$this->assertEquals( '', $key );
	}

	public function testGetCacheKeyWithCacheEnabled(): void
	{
		// Set up cache in registry
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => true] );
		$cache = new ViewCache( $storage, true, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();
		$view->setController( 'test' );
		$view->setPage( 'index' );

		$key = $view->exposedGetCacheKey( ['id' => 1] );

		$this->assertNotEmpty( $key );
		$this->assertIsString( $key );
	}

	public function testGetCacheKeyWithExplicitlyEnabledCache(): void
	{
		// Set up cache in registry but disabled globally
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => false] );
		$cache = new ViewCache( $storage, false, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();
		$view->setController( 'test' );
		$view->setPage( 'index' );
		$view->setCacheEnabled( true ); // Explicitly enable at view level

		$key = $view->exposedGetCacheKey( ['id' => 1] );

		$this->assertNotEmpty( $key );
	}

	public function testGetCachedContentWithCacheDisabled(): void
	{
		$view = new TestableView();
		$view->setCacheEnabled( false );

		$content = $view->exposedGetCachedContent( 'test_key' );

		$this->assertNull( $content );
	}

	public function testGetCachedContentWithoutCache(): void
	{
		$view = new TestableView();

		$content = $view->exposedGetCachedContent( 'test_key' );

		$this->assertNull( $content );
	}

	public function testGetCachedContentWithCacheHit(): void
	{
		// Set up cache with content
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => true] );
		$cache = new ViewCache( $storage, true, 3600, $config );
		$cache->set( 'test_key', 'cached_content' );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();
		$view->setController( 'test' );
		$view->setPage( 'index' );

		$content = $view->exposedGetCachedContent( 'test_key' );

		$this->assertEquals( 'cached_content', $content );
	}

	public function testGetCachedContentWithCacheMiss(): void
	{
		// Set up empty cache
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => true] );
		$cache = new ViewCache( $storage, true, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();
		$view->setController( 'test' );
		$view->setPage( 'index' );

		$content = $view->exposedGetCachedContent( 'nonexistent_key' );

		$this->assertNull( $content );
	}

	public function testGetCachedContentWithExplicitlyEnabledCache(): void
	{
		// Set up cache disabled globally
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => false] );
		$cache = new ViewCache( $storage, false, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		// Store content using reflection
		$reflection = new \ReflectionObject( $cache );
		$enabledProperty = $reflection->getProperty( '_enabled' );
		$enabledProperty->setAccessible( true );
		$enabledProperty->setValue( $cache, true );
		$cache->set( 'test_key', 'cached_content' );
		$enabledProperty->setValue( $cache, false );

		$view = new TestableView();
		$view->setController( 'test' );
		$view->setPage( 'index' );
		$view->setCacheEnabled( true ); // Explicitly enable at view level

		$content = $view->exposedGetCachedContent( 'test_key' );

		$this->assertEquals( 'cached_content', $content );
	}

	public function testSetCachedContentWithCacheDisabled(): void
	{
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => true] );
		$cache = new ViewCache( $storage, true, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();
		$view->setCacheEnabled( false );

		$view->exposedSetCachedContent( 'test_key', 'test_content' );

		// Verify content was not cached
		$this->assertNull( $cache->get( 'test_key' ) );
	}

	public function testSetCachedContentWithoutCache(): void
	{
		$view = new TestableView();

		// Should not throw exception
		$view->exposedSetCachedContent( 'test_key', 'test_content' );

		$this->assertTrue( true );
	}

	public function testSetCachedContentWithCacheEnabled(): void
	{
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => true] );
		$cache = new ViewCache( $storage, true, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();

		$view->exposedSetCachedContent( 'test_key', 'test_content' );

		// Verify content was cached
		$this->assertEquals( 'test_content', $cache->get( 'test_key' ) );
	}

	public function testSetCachedContentWithExplicitlyEnabledCache(): void
	{
		// Set up cache disabled globally
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => false] );
		$cache = new ViewCache( $storage, false, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();
		$view->setCacheEnabled( true ); // Explicitly enable at view level

		$view->exposedSetCachedContent( 'test_key', 'test_content' );

		// Verify content was cached by checking storage directly
		// Need to enable cache temporarily to read
		$reflection = new \ReflectionObject( $cache );
		$enabledProperty = $reflection->getProperty( '_enabled' );
		$enabledProperty->setAccessible( true );
		$enabledProperty->setValue( $cache, true );
		$content = $cache->get( 'test_key' );
		$enabledProperty->setValue( $cache, false );

		$this->assertEquals( 'test_content', $content );
	}

	public function testIsCacheEnabledWithNoCacheSetting(): void
	{
		$view = new TestableView();

		$enabled = $view->exposedIsCacheEnabled();

		$this->assertFalse( $enabled );
	}

	public function testIsCacheEnabledWithViewLevelTrue(): void
	{
		$view = new TestableView();
		$view->setCacheEnabled( true );

		$enabled = $view->exposedIsCacheEnabled();

		$this->assertTrue( $enabled );
	}

	public function testIsCacheEnabledWithViewLevelFalse(): void
	{
		// Set up cache enabled globally
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => true] );
		$cache = new ViewCache( $storage, true, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();
		$view->setCacheEnabled( false ); // Override to false at view level

		$enabled = $view->exposedIsCacheEnabled();

		$this->assertFalse( $enabled );
	}

	public function testIsCacheEnabledFromGlobalCache(): void
	{
		// Set up cache enabled globally
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => true] );
		$cache = new ViewCache( $storage, true, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();

		$enabled = $view->exposedIsCacheEnabled();

		$this->assertTrue( $enabled );
	}

	public function testRenderWithCaching(): void
	{
		// Set up cache
		$storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
		$config = new CacheConfig( ['enabled' => true] );
		$cache = new ViewCache( $storage, true, 3600, $config );
		Registry::getInstance()->set( 'ViewCache', $cache );

		$view = new TestableView();
		$view->setController( 'test' );
		$view->setPage( 'index' );

		// First render - should cache
		$result1 = $view->render( ['id' => 1] );
		$this->assertStringContainsString( 'rendered:', $result1 );

		// Second render - should hit cache
		$result2 = $view->render( ['id' => 1] );
		$this->assertEquals( $result1, $result2 );
	}
}
