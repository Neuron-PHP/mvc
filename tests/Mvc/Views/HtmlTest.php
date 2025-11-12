<?php

namespace Mvc\Views;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Mvc\Views\Html;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
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
		Registry::getInstance()->set( 'Views.Path', vfsStream::url( 'views' ) );
	}

	protected function tearDown(): void
	{
		// Clear registry
		Registry::getInstance()->set( 'Views.Path', null );
		Registry::getInstance()->set( 'Base.Path', null );
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
		Registry::getInstance()->set( 'Views.Path', null );
		
		// Set Base.Path instead
		Registry::getInstance()->set( 'Base.Path', vfsStream::url( 'views' ) );
		
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
}
