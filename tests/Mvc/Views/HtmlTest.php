<?php

namespace Mvc\Views;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Mvc\Views\Html;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use Neuron\Core\Registry\RegistryKeys;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class HtmlTest extends TestCase
{
	private $Root;

	protected function setUp(): void
	{
		$this->Root = vfsStream::setup( 'views' );
		
		// Create the necessary view structure
		$BaseDir = vfsStream::newDirectory( 'base' )->at( $this->Root );
		$LayoutsDir = vfsStream::newDirectory( 'layouts' )->at( $this->Root );
		
		// Create the view file
		$ViewContent = '<?php echo $var_one; ?>';
		vfsStream::newFile( 'index.php' )
			->at( $BaseDir )
			->withContent( $ViewContent );
			
		// Create the layout file
		$LayoutContent = '<html><body><?php echo $content; ?></body></html>';
		vfsStream::newFile( 'default.php' )
			->at( $LayoutsDir )
			->withContent( $LayoutContent );
			
		// Set the views path in registry
		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, vfsStream::url( 'views' ) );
	}

	protected function tearDown(): void
	{
		// Clear registry
		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, null );
		Registry::getInstance()->set( RegistryKeys::BASE_PATH, null );
		Registry::getInstance()->set( 'Cache.Config', null );
	}

	public function testRender()
	{
		$Base = new Base( new Application() );

		$Result = $Base->renderHtml(
			HttpResponseStatus::OK,
			[
				'var_one' => 'test variable',
				'two'     => 2,
				'three'   => 3
			]
		);

		$this->assertStringContainsString( "test variable", $Result );
	}
	
	public function testViewNotFound()
	{
		$Html = new Html();
		$Html->setController( 'NonExistent' );
		$Html->setPage( 'missing' );
		$Html->setLayout( 'default' );
		
		$this->expectException( NotFound::class );
		$this->expectExceptionMessage( 'View notfound:' );
		
		$Html->render( [] );
	}
	
	public function testLayoutNotFound()
	{
		$Html = new Html();
		$Html->setController( 'Base' );
		$Html->setPage( 'index' );
		$Html->setLayout( 'missing' );
		
		$this->expectException( NotFound::class );
		$this->expectExceptionMessage( 'View notfound:' );
		
		$Html->render( [] );
	}
	
	public function testRenderWithoutRegistryPath()
	{
		// Clear registry path
		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, null );
		
		// Set Base.Path instead
		Registry::getInstance()->set( RegistryKeys::BASE_PATH, vfsStream::url( 'views' ) );
		
		// Create resources/views structure
		$ResourcesDir = vfsStream::newDirectory( 'resources' )->at( $this->Root );
		$ViewsDir = vfsStream::newDirectory( 'views' )->at( $ResourcesDir );
		$BaseDir = vfsStream::newDirectory( 'base' )->at( $ViewsDir );
		$LayoutsDir = vfsStream::newDirectory( 'layouts' )->at( $ViewsDir );
		
		// Create the view file
		$ViewContent = '<?php echo $test; ?>';
		vfsStream::newFile( 'page.php' )
			->at( $BaseDir )
			->withContent( $ViewContent );
			
		// Create the layout file
		$LayoutContent = '<div><?php echo $content; ?></div>';
		vfsStream::newFile( 'simple.php' )
			->at( $LayoutsDir )
			->withContent( $LayoutContent );
		
		$Html = new Html();
		$Html->setController( 'Base' );
		$Html->setPage( 'page' );
		$Html->setLayout( 'simple' );
		
		$Result = $Html->render( [ 'test' => 'fallback test' ] );
		
		$this->assertStringContainsString( 'fallback test', $Result );
		$this->assertStringContainsString( '<div>', $Result );
	}
	
	public function testRenderWithCache()
	{
		// Set up cache configuration
		$CacheConfig = [
			'enabled' => true,
			'storage' => 'file',
			'path' => vfsStream::url( 'views/cache' ),
			'ttl' => 3600,
			'views' => [
				'html' => true
			]
		];
		Registry::getInstance()->set( 'Cache.Config', $CacheConfig );
		
		$Html = new Html();
		$Html->setController( 'Base' );
		$Html->setPage( 'index' );
		$Html->setLayout( 'default' );
		
		// First render - should cache
		$Result1 = $Html->render( [ 'var_one' => 'cached value' ] );
		$this->assertStringContainsString( 'cached value', $Result1 );
		
		// Second render - should use cache
		$Result2 = $Html->render( [ 'var_one' => 'cached value' ] );
		$this->assertEquals( $Result1, $Result2 );
		
		// Different data should produce different cache
		$Result3 = $Html->render( [ 'var_one' => 'different value' ] );
		$this->assertStringContainsString( 'different value', $Result3 );
		$this->assertNotEquals( $Result1, $Result3 );
	}

	public function testSiteViewWinsOverPackageView(): void
	{
		$site = vfsStream::newDirectory( 'site' )->at( $this->Root );
		$package = vfsStream::newDirectory( 'package' )->at( $this->Root );

		$siteBlog = vfsStream::newDirectory( 'blog' )->at( $site );
		$packageBlog = vfsStream::newDirectory( 'blog' )->at( $package );
		$siteLayouts = vfsStream::newDirectory( 'layouts' )->at( $site );

		vfsStream::newFile( 'show.php' )->at( $siteBlog )->withContent( 'SITE BODY' );
		vfsStream::newFile( 'show.php' )->at( $packageBlog )->withContent( 'PACKAGE BODY' );
		vfsStream::newFile( 'default.php' )->at( $siteLayouts )->withContent( '<wrap><?php echo $content; ?></wrap>' );

		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, [
			vfsStream::url( 'views/site' ),
			vfsStream::url( 'views/package' ),
		] );

		$Html = new Html();
		$Html->setController( 'Blog' );
		$Html->setPage( 'show' );
		$Html->setLayout( 'default' );

		$Result = $Html->render( [] );

		$this->assertStringContainsString( 'SITE BODY', $Result );
		$this->assertStringNotContainsString( 'PACKAGE BODY', $Result );
	}

	public function testMissingSiteViewFallsThroughToPackage(): void
	{
		$site = vfsStream::newDirectory( 'site-empty' )->at( $this->Root );
		$package = vfsStream::newDirectory( 'package-blog' )->at( $this->Root );

		vfsStream::newDirectory( 'layouts' )->at( $site );
		vfsStream::newFile( 'default.php' )
			->at( $site->getChild( 'layouts' ) )
			->withContent( '<site><?php echo $content; ?></site>' );

		$packageBlog = vfsStream::newDirectory( 'blog' )->at( $package );
		vfsStream::newFile( 'show.php' )->at( $packageBlog )->withContent( 'PACKAGE BODY' );

		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, [
			vfsStream::url( 'views/site-empty' ),
			vfsStream::url( 'views/package-blog' ),
		] );

		$Html = new Html();
		$Html->setController( 'Blog' );
		$Html->setPage( 'show' );
		$Html->setLayout( 'default' );

		$Result = $Html->render( [] );

		$this->assertStringContainsString( 'PACKAGE BODY', $Result );
		$this->assertStringContainsString( '<site>', $Result );
	}

	public function testNotFoundWhenNoRootHasTheView(): void
	{
		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, [
			vfsStream::url( 'views/missing-a' ),
			vfsStream::url( 'views/missing-b' ),
		] );

		$Html = new Html();
		$Html->setController( 'Blog' );
		$Html->setPage( 'missing' );
		$Html->setLayout( 'default' );

		$this->expectException( NotFound::class );
		$this->expectExceptionMessage( 'View notfound:' );

		$Html->render( [] );
	}
}
