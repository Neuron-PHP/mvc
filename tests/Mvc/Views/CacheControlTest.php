<?php

namespace Mvc\Views;

use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Mvc\Views\Html;
use Neuron\Mvc\Views\Markdown;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class CacheControlTest extends TestCase
{
	private $Root;
	private $Registry;
	
	protected function setUp(): void
	{
		parent::setUp();
		
		// Create virtual filesystem
		$this->Root = vfsStream::setup( 'test' );
		
		// Set up registry
		$this->Registry = Registry::getInstance();
		
		// Clear any existing cache from registry
		$this->Registry->set( 'ViewCache', null );
		
		// Set up base path and views path
		$this->Registry->set( 'Base.Path', vfsStream::url( 'test' ) );
		$this->Registry->set( 'Views.Path', vfsStream::url( 'test/resources/views' ) );
		
		// Create view directories
		vfsStream::newDirectory( 'resources/views/test' )->at( $this->Root );
		vfsStream::newDirectory( 'resources/views/test_controller_with_cache' )->at( $this->Root );
		vfsStream::newDirectory( 'resources/views/layouts' )->at( $this->Root );
		vfsStream::newDirectory( 'cache/views' )->at( $this->Root );
		
		// Create test view files
		$ViewContent = '<?php echo "Test Content"; ?>';
		$LayoutContent = '<?php echo $content; ?>';
		
		vfsStream::newFile( 'resources/views/test/index.php' )
			->at( $this->Root )
			->setContent( $ViewContent );
			
		vfsStream::newFile( 'resources/views/test_controller_with_cache/index.php' )
			->at( $this->Root )
			->setContent( $ViewContent );
			
		vfsStream::newFile( 'resources/views/layouts/default.php' )
			->at( $this->Root )
			->setContent( $LayoutContent );
			
		// Create markdown view
		vfsStream::newFile( 'resources/views/test/page.md' )
			->at( $this->Root )
			->setContent( '# Test Page' );
			
		vfsStream::newFile( 'resources/views/test_controller_with_cache/page.md' )
			->at( $this->Root )
			->setContent( '# Test Page' );
	}
	
	protected function tearDown(): void
	{
		// Clear cache from registry
		$this->Registry->set( 'ViewCache', null );
		$this->Registry->set( 'Base.Path', null );
		parent::tearDown();
	}
	
	/**
	 * Test that cache can be disabled at view level
	 */
	public function testCacheDisabledAtViewLevel()
	{
		// Set up cache in registry (enabled globally)
		$CachePath = vfsStream::url( 'test/cache/views' );
		$Storage = new FileCacheStorage( $CachePath );
		$Cache = new ViewCache( $Storage, true );
		$this->Registry->set( 'ViewCache', $Cache );
		
		// Create view with cache disabled
		$View = new Html();
		$View->setController( 'Test' )
			->setPage( 'index' )
			->setLayout( 'default' )
			->setCacheEnabled( false );
		
		// Render view
		$Content = $View->render( [] );
		
		// Check cache directory for any cache files
		$CacheDir = $this->Root->getChild( 'cache/views' );
		$HasCacheFiles = false;
		if( $CacheDir && $CacheDir->hasChildren() )
		{
			foreach( $CacheDir->getChildren() as $Child )
			{
				if( $Child->hasChildren() )
				{
					$HasCacheFiles = true;
					break;
				}
			}
		}
		
		// No cache files should have been created
		$this->assertFalse( $HasCacheFiles, 'Cache files were created even though cache was disabled' );
	}
	
	/**
	 * Test that cache can be enabled at view level even if globally disabled
	 */
	public function testCacheEnabledAtViewLevel()
	{
		// Set up cache in registry (disabled globally)
		$CachePath = vfsStream::url( 'test/cache/views' );
		$Storage = new FileCacheStorage( $CachePath );
		$Cache = new ViewCache( $Storage, false ); // Globally disabled
		$this->Registry->set( 'ViewCache', $Cache );
		
		// Create view with cache explicitly enabled
		$View = new Html();
		$View->setController( 'Test' )
			->setPage( 'index' )
			->setLayout( 'default' )
			->setCacheEnabled( true );
		
		// Render view first time
		$Content1 = $View->render( [] );
		$this->assertStringContainsString( 'Test Content', $Content1 );
		
		// Modify the view file to have different content
		$this->Root->getChild( 'resources/views/test/index.php' )
			->setContent( '<?php echo "Modified Content"; ?>' );
		
		// Create a new view instance with cache enabled
		$View2 = new Html();
		$View2->setController( 'Test' )
			->setPage( 'index' )
			->setLayout( 'default' )
			->setCacheEnabled( true );
		
		// Render view second time (should return cached content)
		$Content2 = $View2->render( [] );
		
		// Both renders should return the same content (from cache)
		$this->assertEquals( $Content1, $Content2 );
		$this->assertStringContainsString( 'Test Content', $Content2 );
		$this->assertStringNotContainsString( 'Modified Content', $Content2 );
	}
	
	/**
	 * Test controller render methods with cache control
	 */
	public function testControllerRenderWithCacheControl()
	{
		// Set up cache in registry
		$CachePath = vfsStream::url( 'test/cache/views' );
		$Storage = new FileCacheStorage( $CachePath );
		$Cache = new ViewCache( $Storage, true );
		$this->Registry->set( 'ViewCache', $Cache );
		
		// Create mock router
		$Router = $this->createMock( Router::class );
		
		// Create test controller
		$Controller = new TestControllerWithCache();
		
		// Test rendering with cache disabled
		$HtmlContent = $Controller->renderHtml( 
			HttpResponseStatus::OK, 
			[], 
			'index', 
			'default', 
			false // Cache disabled
		);
		
		$this->assertStringContainsString( 'Test Content', $HtmlContent );
		
		// Check that no cache was created
		$CacheFiles = glob( $CachePath . '/*' );
		$this->assertEmpty( $CacheFiles );
	}
	
	/**
	 * Test markdown render with cache control
	 */
	public function testMarkdownRenderWithCacheControl()
	{
		// Use real filesystem for Markdown test due to vfsStream incompatibility with recursive file search
		$TempDir = sys_get_temp_dir() . '/neuron_test_markdown_' . uniqid();

		try
		{
			// Create directory structure
			$ViewsDir = $TempDir . '/resources/views';
			$CacheDir = $TempDir . '/cache/views';
			$ControllerDir = $ViewsDir . '/test_controller_with_cache';
			$LayoutsDir = $ViewsDir . '/layouts';

			mkdir( $ControllerDir, 0777, true );
			mkdir( $LayoutsDir, 0777, true );
			mkdir( $CacheDir, 0777, true );

			// Create Markdown view file
			file_put_contents( $ControllerDir . '/page.md', '# Test Markdown Page' );

			// Create layout file
			file_put_contents( $LayoutsDir . '/default.php', '<?php echo $content; ?>' );

			// Configure Registry with real paths
			$OriginalBasePath = $this->Registry->get( 'Base.Path' );
			$OriginalViewsPath = $this->Registry->get( 'Views.Path' );
			$OriginalViewCache = $this->Registry->get( 'ViewCache' );

			$this->Registry->set( 'Base.Path', $TempDir );
			$this->Registry->set( 'Views.Path', $ViewsDir );

			// Set up cache
			$Storage = new FileCacheStorage( $CacheDir );
			$Cache = new ViewCache( $Storage, true );
			$this->Registry->set( 'ViewCache', $Cache );

			// Create test controller
			$Controller = new TestControllerWithCache();

			// Test rendering with cache enabled
			$MarkdownContent = $Controller->renderMarkdown(
				HttpResponseStatus::OK,
				[],
				'page',
				'default',
				true // Cache enabled
			);

			$this->assertStringContainsString( '<h1>Test Markdown Page</h1>', $MarkdownContent );

			// Render again - should use cache
			$MarkdownContent2 = $Controller->renderMarkdown(
				HttpResponseStatus::OK,
				[],
				'page',
				'default',
				true
			);

			$this->assertEquals( $MarkdownContent, $MarkdownContent2 );

			// Restore original registry values
			$this->Registry->set( 'Base.Path', $OriginalBasePath );
			$this->Registry->set( 'Views.Path', $OriginalViewsPath );
			$this->Registry->set( 'ViewCache', $OriginalViewCache );
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
	
	/**
	 * Test that null cache setting uses global configuration
	 */
	public function testNullCacheSettingUsesGlobal()
	{
		// Set up cache in registry (enabled globally)
		$CachePath = vfsStream::url( 'test/cache/views' );
		$Storage = new FileCacheStorage( $CachePath );
		$Cache = new ViewCache( $Storage, true );
		$this->Registry->set( 'ViewCache', $Cache );
		
		// Create view with null cache setting (default)
		$View = new Html();
		$View->setController( 'Test' )
			->setPage( 'index' )
			->setLayout( 'default' );
			// Not calling setCacheEnabled, so it remains null
		
		// Render view first time
		$Content1 = $View->render( [] );
		
		// Modify the view file
		vfsStream::newFile( 'resources/views/test/index.php' )
			->at( $this->Root )
			->setContent( '<?php echo "Modified Content"; ?>' );
		
		// Render view second time (should return cached content because global is enabled)
		$Content2 = $View->render( [] );
		
		// Both renders should return the same content (from cache)
		$this->assertEquals( $Content1, $Content2 );
		$this->assertStringContainsString( 'Test Content', $Content2 );
	}
}

/**
 * Test controller class for cache control testing
 */
class TestControllerWithCache extends Base
{
	// Inherits all render methods from Base
}
