<?php

namespace Tests\Mvc\Controllers;

use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use PHPUnit\Framework\TestCase;

class CacheKeyDataTest extends TestCase
{
	private string $TempCacheDir;
	private string $TempViewsDir;
	
	protected function setUp(): void
	{
		parent::setUp();
		
		// Clear registry keys we'll use
		$Registry = Registry::getInstance();
		$Registry->set( 'ViewCache', null );
		$Registry->set( 'Settings', null );
		$Registry->set( 'Base.Path', null );
		$Registry->set( 'Views.Path', null );
		
		// Create temp directories
		$this->TempCacheDir = sys_get_temp_dir() . '/cache_key_test_' . uniqid();
		$this->TempViewsDir = sys_get_temp_dir() . '/views_test_' . uniqid();
		mkdir( $this->TempCacheDir );
		mkdir( $this->TempViewsDir );
		
		// Set up views directory structure
		$ControllerDir = $this->TempViewsDir . '/CacheKeyTestController';
		mkdir( $ControllerDir );
		
		// Create layouts directory
		$LayoutsDir = $this->TempViewsDir . '/layouts';
		mkdir( $LayoutsDir );
		
		// Create default layout
		file_put_contents( 
			$LayoutsDir . '/default.php', 
			'<!DOCTYPE html><html><head><title>Test</title></head><body><?= $Content ?></body></html>' 
		);
		
		// Create test view files
		file_put_contents( 
			$ControllerDir . '/static.php', 
			'Static content: <?= $content ?? "default" ?>' 
		);
		
		file_put_contents( 
			$ControllerDir . '/product.php', 
			'Product: <?= $product_name ?? "unknown" ?> - Price: <?= $price ?? "0" ?>' 
		);
		
		file_put_contents( 
			$ControllerDir . '/index.md', 
			'# Index Page' . PHP_EOL . 'Content: <?= $content ?? "default" ?>' 
		);
		
		// Set Views.Path for view resolution
		$Registry->set( 'Views.Path', $this->TempViewsDir );
	}
	
	protected function tearDown(): void
	{
		parent::tearDown();
		
		// Clean up temp directories
		if( is_dir( $this->TempCacheDir ) )
		{
			$this->deleteDirectory( $this->TempCacheDir );
		}
		if( is_dir( $this->TempViewsDir ) )
		{
			$this->deleteDirectory( $this->TempViewsDir );
		}
		
		// Clear registry
		$Registry = Registry::getInstance();
		$Registry->set( 'ViewCache', null );
		$Registry->set( 'Settings', null );
		$Registry->set( 'Base.Path', null );
		$Registry->set( 'Views.Path', null );
	}
	
	public function testHasViewCacheByKeyWithEmptyCacheKey()
	{
		// Setup cache
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		
		// Generate cache key with empty data
		$CacheKey = $ViewCache->generateKey( 'CacheKeyTestController', 'static', [] );
		$ViewCache->set( $CacheKey, '<html>Cached static content</html>' );
		
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller and test
		$Controller = new CacheKeyTestController();
		
		// Should find cache with empty key
		$this->assertTrue( $Controller->testHasViewCacheByKey( 'static', [] ) );
		
		// Should not find cache with different key
		$this->assertFalse( $Controller->testHasViewCacheByKey( 'static', ['id' => 1] ) );
	}
	
	public function testHasViewCacheByKeyWithNonEmptyCacheKey()
	{
		// Setup cache
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		
		// Generate cache key with specific data
		$CacheKeyData = ['product_id' => 123, 'variant' => 'blue'];
		$CacheKey = $ViewCache->generateKey( 'CacheKeyTestController', 'product', $CacheKeyData );
		$ViewCache->set( $CacheKey, '<html>Product 123 blue</html>' );
		
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller and test
		$Controller = new CacheKeyTestController();
		
		// Should find cache with exact key
		$this->assertTrue( $Controller->testHasViewCacheByKey( 'product', ['product_id' => 123, 'variant' => 'blue'] ) );
		
		// Should not find cache with different key
		$this->assertFalse( $Controller->testHasViewCacheByKey( 'product', ['product_id' => 456, 'variant' => 'blue'] ) );
		$this->assertFalse( $Controller->testHasViewCacheByKey( 'product', ['product_id' => 123, 'variant' => 'red'] ) );
		$this->assertFalse( $Controller->testHasViewCacheByKey( 'product', [] ) );
	}
	
	public function testGetViewCacheByKeyReturnsContent()
	{
		// Setup cache
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		
		$TestContent = '<html><body>Cached product page</body></html>';
		$CacheKeyData = ['id' => 789];
		$CacheKey = $ViewCache->generateKey( 'CacheKeyTestController', 'product', $CacheKeyData );
		$ViewCache->set( $CacheKey, $TestContent );
		
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller and test
		$Controller = new CacheKeyTestController();
		
		// Should get cached content with correct key
		$CachedContent = $Controller->testGetViewCacheByKey( 'product', ['id' => 789] );
		$this->assertEquals( $TestContent, $CachedContent );
		
		// Should return null with wrong key
		$this->assertNull( $Controller->testGetViewCacheByKey( 'product', ['id' => 999] ) );
		$this->assertNull( $Controller->testGetViewCacheByKey( 'product', [] ) );
	}
	
	public function testRenderHtmlWithCacheKeyUsesCacheWhenAvailable()
	{
		// Setup cache with pre-cached content
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		
		$CachedContent = '<html><body>Pre-cached static content</body></html>';
		$CacheKey = $ViewCache->generateKey( 'CacheKeyTestController', 'static', [] );
		$ViewCache->set( $CacheKey, $CachedContent );
		
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller
		$Controller = new CacheKeyTestController();
		
		// Render with empty view data - should use cache
		$Result = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			[],  // Empty view data
			[],  // Empty cache key data
			'static',
			'default',
			true
		);
		
		$this->assertEquals( $CachedContent, $Result );
	}
	
	public function testRenderHtmlWithCacheKeyStoresInCacheWhenMissing()
	{
		// Setup empty cache
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller
		$Controller = new CacheKeyTestController();
		
		// Cache key data (determines cache uniqueness)
		$CacheKeyData = ['product_id' => 456];
		
		// Full view data (for rendering)
		$ViewData = [
			'product_name' => 'Test Product',
			'price' => 99.99,
			'description' => 'This is a test product'
		];
		
		// Verify cache doesn't exist yet
		$this->assertFalse( $Controller->testHasViewCacheByKey( 'product', $CacheKeyData ) );
		
		// Render with full data - should store in cache
		$Result = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			$ViewData,
			$CacheKeyData,
			'product',
			'default',
			true
		);
		
		// Verify it was rendered with the data
		$this->assertStringContainsString( 'Test Product', $Result );
		$this->assertStringContainsString( '99.99', $Result );
		
		// Verify it was cached using cache key data
		$this->assertTrue( $Controller->testHasViewCacheByKey( 'product', $CacheKeyData ) );
		
		// Verify we can retrieve it with just cache key data
		$CachedContent = $Controller->testGetViewCacheByKey( 'product', $CacheKeyData );
		$this->assertEquals( $Result, $CachedContent );
	}
	
	public function testRenderMarkdownWithCacheKeyUsesCacheWhenAvailable()
	{
		// Setup cache with pre-cached content
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		
		$CachedContent = '<h1>Cached Markdown</h1><p>This is cached</p>';
		$CacheKey = $ViewCache->generateKey( 'CacheKeyTestController', 'index', [] );
		$ViewCache->set( $CacheKey, $CachedContent );
		
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller
		$Controller = new CacheKeyTestController();
		
		// Render with empty view data - should use cache
		$Result = $Controller->renderMarkdownWithCacheKey(
			HttpResponseStatus::OK,
			[],  // Empty view data
			[],  // Empty cache key data
			'index',
			'default',
			true
		);
		
		$this->assertEquals( $CachedContent, $Result );
	}
	
	public function testEmptyCacheKeyAlwaysGeneratesSameKey()
	{
		// Setup cache
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		
		// Generate keys with empty array multiple times
		$Key1 = $ViewCache->generateKey( 'TestController', 'page', [] );
		$Key2 = $ViewCache->generateKey( 'TestController', 'page', [] );
		$Key3 = $ViewCache->generateKey( 'TestController', 'page', [] );
		
		// All should be identical
		$this->assertEquals( $Key1, $Key2 );
		$this->assertEquals( $Key2, $Key3 );
		
		// Different page should generate different key
		$DifferentPageKey = $ViewCache->generateKey( 'TestController', 'other', [] );
		$this->assertNotEquals( $Key1, $DifferentPageKey );
		
		// Non-empty array should generate different key
		$NonEmptyKey = $ViewCache->generateKey( 'TestController', 'page', ['id' => 1] );
		$this->assertNotEquals( $Key1, $NonEmptyKey );
	}
	
	public function testCacheKeyDataIsSeparateFromViewData()
	{
		// Setup cache
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller
		$Controller = new CacheKeyTestController();
		
		// Simple cache key data (just the ID)
		$CacheKeyData = ['id' => 100];
		
		// Complex view data (includes API response)
		$ViewData = [
			'id' => 100,
			'product_name' => 'Complex Product',
			'price' => 199.99,
			'api_response' => [
				'inventory' => 50,
				'warehouse' => 'A',
				'last_updated' => '2024-01-01'
			]
		];
		
		// First render with full data
		$Result1 = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			$ViewData,
			$CacheKeyData,
			'product',
			'default',
			true
		);
		
		// Now we can check cache with just the key data (not the full API response)
		$this->assertTrue( $Controller->testHasViewCacheByKey( 'product', $CacheKeyData ) );
		
		// And retrieve cached content without the API data
		$Result2 = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			[],  // No view data needed!
			$CacheKeyData,
			'product',
			'default',
			true
		);
		
		$this->assertEquals( $Result1, $Result2 );
	}
	
	public function testRenderWithCacheKeyWhenCacheDisabled()
	{
		// No cache setup - cache is disabled
		
		// Create controller
		$Controller = new CacheKeyTestController();
		
		$ViewData = ['product_name' => 'Test', 'price' => 50];
		$CacheKeyData = ['id' => 1];
		
		// Should still render even with cache disabled
		$Result = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			$ViewData,
			$CacheKeyData,
			'product',
			'default',
			false  // Cache explicitly disabled
		);
		
		$this->assertStringContainsString( 'Test', $Result );
		$this->assertStringContainsString( '50', $Result );
		
		// Should not be cached
		$this->assertFalse( $Controller->testHasViewCacheByKey( 'product', $CacheKeyData ) );
	}
	
	public function testRenderWithCacheKeyRespectsDefaultSettings()
	{
		// Setup cache that is enabled by default
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, true, 3600 );  // Cache enabled
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller
		$Controller = new CacheKeyTestController();
		
		$ViewData = ['product_name' => 'Default Test', 'price' => 75];
		$CacheKeyData = ['id' => 999];
		
		// Render with null (should use default - enabled)
		$Result1 = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			$ViewData,
			$CacheKeyData,
			'product',
			'default',
			null  // Use default settings
		);
		
		$this->assertStringContainsString( 'Default Test', $Result1 );
		
		// Should be cached because default is enabled
		$this->assertTrue( $Controller->testHasViewCacheByKey( 'product', $CacheKeyData ) );
		
		// Now render again with empty data - should use cache
		$Result2 = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			[],  // Empty data
			$CacheKeyData,
			'product',
			'default',
			null  // Use default settings
		);
		
		$this->assertEquals( $Result1, $Result2 );
	}
	
	public function testRenderWithCacheKeyRespectsDefaultDisabled()
	{
		// Setup cache that is disabled by default
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, false, 3600 );  // Cache disabled
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller
		$Controller = new CacheKeyTestController();
		
		$ViewData = ['product_name' => 'Disabled Test', 'price' => 25];
		$CacheKeyData = ['id' => 888];
		
		// Render with null (should use default - disabled)
		$Result = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			$ViewData,
			$CacheKeyData,
			'product',
			'default',
			null  // Use default settings
		);
		
		$this->assertStringContainsString( 'Disabled Test', $Result );
		
		// Should NOT be cached because default is disabled
		$this->assertFalse( $Controller->testHasViewCacheByKey( 'product', $CacheKeyData ) );
	}
	
	public function testRenderWithCacheKeyOverridesDefault()
	{
		// Setup cache that is disabled by default
		$Storage = new FileCacheStorage( $this->TempCacheDir );
		$ViewCache = new ViewCache( $Storage, false, 3600 );  // Cache disabled by default
		Registry::getInstance()->set( 'ViewCache', $ViewCache );
		
		// Create controller
		$Controller = new CacheKeyTestController();
		
		$ViewData = ['product_name' => 'Override Test', 'price' => 100];
		$CacheKeyData = ['id' => 777];
		
		// Render with true (should override default and enable cache)
		$Result = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			$ViewData,
			$CacheKeyData,
			'product',
			'default',
			true  // Override default - force enable
		);
		
		$this->assertStringContainsString( 'Override Test', $Result );
		
		// The cache was stored when we forced it with true
		// To verify, enable the cache globally and check if the content exists
		$ViewCache->setEnabled( true );
		$CacheKey = $ViewCache->generateKey( 'CacheKeyTestController', 'product', $CacheKeyData );
		$this->assertTrue( $ViewCache->exists( $CacheKey ), 'Cache should exist after forcing with true' );
		
		// Now test the opposite - cache enabled by default, but override to disable
		$ViewData2 = ['product_name' => 'Override2', 'price' => 200];
		$CacheKeyData2 = ['id' => 666];
		
		$Result2 = $Controller->renderHtmlWithCacheKey(
			HttpResponseStatus::OK,
			$ViewData2,
			$CacheKeyData2,
			'product',
			'default',
			false  // Override default - force disable
		);
		
		$this->assertStringContainsString( 'Override2', $Result2 );
		
		// Should NOT be cached even though default is enabled
		$CacheKey2 = $ViewCache->generateKey( 'CacheKeyTestController', 'product', $CacheKeyData2 );
		$this->assertFalse( $ViewCache->exists( $CacheKey2 ), 'Cache should not exist after forcing with false' );
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

// Test controller that exposes protected methods
class CacheKeyTestController extends Base
{
	public function testHasViewCacheByKey( string $Page, array $CacheKeyData = [] ): bool
	{
		return $this->hasViewCacheByKey( $Page, $CacheKeyData );
	}
	
	public function testGetViewCacheByKey( string $Page, array $CacheKeyData = [] ): ?string
	{
		return $this->getViewCacheByKey( $Page, $CacheKeyData );
	}
}
