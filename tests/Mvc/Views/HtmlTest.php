<?php

namespace Mvc\Views;

use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
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
		$BaseDir = vfsStream::newDirectory( 'Base' )->at( $this->Root );
		$LayoutsDir = vfsStream::newDirectory( 'layouts' )->at( $this->Root );
		
		// Create the view file
		$ViewContent = '<?php echo $var_one; ?>';
		vfsStream::newFile( 'index.php' )
			->at( $BaseDir )
			->withContent( $ViewContent );
			
		// Create the layout file
		$LayoutContent = '<html><body><?php echo $Content; ?></body></html>';
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
	}

	public function testRender()
	{
		$Base = new Base( new Router() );

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
}
