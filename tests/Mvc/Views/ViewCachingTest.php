<?php

namespace Tests\Mvc\Views;

use Neuron\Data\Settings\Source\Memory;
use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Mvc\Views\Html;
use Neuron\Mvc\Views\Json;
use Neuron\Mvc\Views\Xml;
use Neuron\Mvc\Views\Markdown;
use Neuron\Patterns\Registry;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class ViewCachingTest extends TestCase
{
	private $Root;
	private $CacheDir;
	private $OriginalRegistry;
	
	protected function setUp(): void
	{
		parent::setUp();
		
		// Create virtual filesystem
		$this->Root = vfsStream::setup( 'test' );
		$this->CacheDir = vfsStream::newDirectory( 'cache' )->at( $this->Root );
		
		// Store original registry values
		$this->OriginalRegistry = [
			'ViewCache' => Registry::getInstance()->get( 'ViewCache' ),
			'Settings' => Registry::getInstance()->get( 'Settings' ),
			'Base.Path' => Registry::getInstance()->get( 'Base.Path' )
		];
		
		// Set base path for views
		Registry::getInstance()->set( 'Base.Path', vfsStream::url( 'test' ) );
	}
	
	protected function tearDown(): void
	{
		// Restore original registry values
		foreach( $this->OriginalRegistry as $Key => $Value )
		{
			Registry::getInstance()->set( $Key, $Value );
		}
		
		parent::tearDown();
	}
	
	/**
	 * Create a cache config with specific view type settings
	 */
	private function createCacheConfig( array $ViewSettings = [] ): CacheConfig
	{
		$Settings = [
			'enabled' => true,
			'path' => vfsStream::url( 'test/cache' ),
			'ttl' => 3600,
			'storage' => 'file'
		];
		
		// Add view settings directly at the top level (flat structure)
		foreach( $ViewSettings as $ViewType => $Enabled )
		{
			$Settings[$ViewType] = $Enabled;
		}
		
		return new CacheConfig( $Settings );
	}
	
	/**
	 * Create and register a ViewCache instance
	 */
	private function registerViewCache( CacheConfig $Config ): ViewCache
	{
		$Storage = new FileCacheStorage( $Config->getCachePath() );
		$Cache = new ViewCache( 
			$Storage, 
			$Config->isEnabled(), 
			$Config->getDefaultTtl(), 
			$Config 
		);
		Registry::getInstance()->set( 'ViewCache', $Cache );
		Registry::getInstance()->set( 'CacheConfig', $Config );
		
		return $Cache;
	}
	
	/**
	 * Test HTML view caching when enabled
	 */
	public function testHtmlViewCachingEnabled()
	{
		$Config = $this->createCacheConfig( [ 'html' => true ] );
		$Cache = $this->registerViewCache( $Config );
		
		// Cache should be enabled for HTML
		$this->assertTrue( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Cache->isEnabled() );
		
		// Generate cache key
		$Key = $Cache->generateKey( 'TestController', 'test', [] );
		
		// Store and retrieve content
		$Content = '<html><body>Test Content</body></html>';
		$Cache->set( $Key, $Content );
		
		$Retrieved = $Cache->get( $Key );
		$this->assertEquals( $Content, $Retrieved );
	}
	
	/**
	 * Test HTML view caching when disabled globally but type enabled
	 */
	public function testHtmlViewTypeEnabledButGlobalDisabled()
	{
		$Config = $this->createCacheConfig( [ 'html' => true ] );
		// Create cache with global disabled
		$Storage = new FileCacheStorage( $Config->getCachePath() );
		$Cache = new ViewCache( 
			$Storage, 
			false, // Global cache disabled
			$Config->getDefaultTtl(), 
			$Config 
		);
		Registry::getInstance()->set( 'ViewCache', $Cache );
		Registry::getInstance()->set( 'CacheConfig', $Config );
		
		// HTML type is enabled but global cache is disabled
		$this->assertTrue( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertFalse( $Cache->isEnabled() );
		
		// Generate cache key
		$Key = $Cache->generateKey( 'TestController', 'test', [] );
		
		// Try to store - won't work because global cache is disabled
		$Content = '<html><body>Test Content</body></html>';
		$Cache->set( $Key, $Content );
		
		// Retrieve should return null
		$Retrieved = $Cache->get( $Key );
		$this->assertNull( $Retrieved );
	}
	
	/**
	 * Test JSON view caching toggle
	 */
	public function testJsonViewCachingToggle()
	{
		// Test with JSON enabled
		$Config1 = $this->createCacheConfig( [ 'json' => true ] );
		$this->assertTrue( $Config1->isViewTypeEnabled( 'json' ) );
		
		// Test with JSON disabled
		$Config2 = $this->createCacheConfig( [ 'json' => false ] );
		$this->assertFalse( $Config2->isViewTypeEnabled( 'json' ) );
		
		// Test default (not specified)
		$Config3 = $this->createCacheConfig();
		$this->assertTrue( $Config3->isViewTypeEnabled( 'json' ) ); // Defaults to true
	}
	
	/**
	 * Test XML view caching toggle
	 */
	public function testXmlViewCachingToggle()
	{
		// Test with XML enabled
		$Config1 = $this->createCacheConfig( [ 'xml' => true ] );
		$this->assertTrue( $Config1->isViewTypeEnabled( 'xml' ) );
		
		// Test with XML disabled
		$Config2 = $this->createCacheConfig( [ 'xml' => false ] );
		$this->assertFalse( $Config2->isViewTypeEnabled( 'xml' ) );
		
		// Test default
		$Config3 = $this->createCacheConfig();
		$this->assertTrue( $Config3->isViewTypeEnabled( 'xml' ) );
	}
	
	/**
	 * Test Markdown view caching toggle
	 */
	public function testMarkdownViewCachingToggle()
	{
		// Test with Markdown enabled
		$Config1 = $this->createCacheConfig( [ 'markdown' => true ] );
		$this->assertTrue( $Config1->isViewTypeEnabled( 'markdown' ) );
		
		// Test with Markdown disabled
		$Config2 = $this->createCacheConfig( [ 'markdown' => false ] );
		$this->assertFalse( $Config2->isViewTypeEnabled( 'markdown' ) );
		
		// Test default
		$Config3 = $this->createCacheConfig();
		$this->assertTrue( $Config3->isViewTypeEnabled( 'markdown' ) );
	}
	
	/**
	 * Test mixed view type settings
	 */
	public function testMixedViewTypeSettings()
	{
		$Config = $this->createCacheConfig( [
			'html' => true,
			'json' => false,
			'xml' => true,
			'markdown' => false
		] );
		
		// Test each view type
		$this->assertTrue( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertFalse( $Config->isViewTypeEnabled( 'json' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'xml' ) );
		$this->assertFalse( $Config->isViewTypeEnabled( 'markdown' ) );
		
		// Cache operations should work when global cache is enabled
		$Cache = $this->registerViewCache( $Config );
		$this->assertTrue( $Cache->isEnabled() );
		
		// Test storing and retrieving
		$Key = $Cache->generateKey( 'Controller', 'page', [] );
		$Cache->set( $Key, 'Test Content' );
		$this->assertEquals( 'Test Content', $Cache->get( $Key ) );
	}
	
	/**
	 * Test default behavior when no view type settings specified
	 */
	public function testDefaultViewTypeSettings()
	{
		$Config = $this->createCacheConfig(); // No view settings
		
		// All view types should default to enabled
		$this->assertTrue( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'json' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'xml' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'markdown' ) );
		
		// Unknown types should also default to true
		$this->assertTrue( $Config->isViewTypeEnabled( 'custom' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'pdf' ) );
	}
	
	/**
	 * Test view-level cache override (dynamic enable/disable)
	 */
	public function testViewLevelCacheOverride()
	{
		$Config = $this->createCacheConfig( [ 'html' => false ] );
		$Cache = $this->registerViewCache( $Config );
		
		// Create HTML view
		$Html = new Html();
		$Html->setController( 'TestController' );
		$Html->setPage( 'test' );
		
		// Initially, caching should follow global setting (null = use global)
		$this->assertNull( $Html->getCacheEnabled() );
		
		// Enable caching at view level
		$Html->setCacheEnabled( true );
		$this->assertTrue( $Html->getCacheEnabled() );
		
		// Disable caching at view level
		$Html->setCacheEnabled( false );
		$this->assertFalse( $Html->getCacheEnabled() );
		
		// Reset to use global setting
		$Html->setCacheEnabled( null );
		$this->assertNull( $Html->getCacheEnabled() );
	}
	
	/**
	 * Test that custom view types default to enabled
	 */
	public function testCustomViewTypeDefault()
	{
		$Config = $this->createCacheConfig( [ 'html' => false ] );
		
		// Custom/unknown view type should default to true
		$this->assertTrue( $Config->isViewTypeEnabled( 'custom' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'pdf' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'csv' ) );
		
		// But configured types should respect their settings
		$this->assertFalse( $Config->isViewTypeEnabled( 'html' ) );
	}
	
	/**
	 * Test partial configuration
	 */
	public function testPartialConfiguration()
	{
		$Config = $this->createCacheConfig( [
			'html' => false,
			'json' => true
			// xml and markdown not specified
		] );
		
		$this->assertFalse( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'json' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'xml' ) );  // Default
		$this->assertTrue( $Config->isViewTypeEnabled( 'markdown' ) ); // Default
	}
	
	/**
	 * Test view type configuration from settings source
	 */
	public function testViewTypeConfigFromSettings()
	{
		$Settings = new Memory();
		$Settings->set( 'cache', 'enabled', 'true' );
		$Settings->set( 'cache', 'html', 'false' );
		$Settings->set( 'cache', 'json', 'true' );
		$Settings->set( 'cache', 'xml', 'false' );
		$Settings->set( 'cache', 'markdown', 'true' );
		
		$Config = CacheConfig::fromSettings( $Settings );
		
		$this->assertTrue( $Config->isEnabled() );
		$this->assertFalse( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'json' ) );
		$this->assertFalse( $Config->isViewTypeEnabled( 'xml' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'markdown' ) );
	}
}
