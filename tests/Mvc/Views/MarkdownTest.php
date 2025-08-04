<?php

namespace Mvc\Views;

use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class MarkdownTest extends TestCase
{
	protected function setUp(): void
	{
		// Clear any existing registry values
		Registry::getInstance()->set( "Views.Path", null );
		Registry::getInstance()->set( "ViewCache", null );
	}

	protected function tearDown(): void
	{
		// Clean up after test
		Registry::getInstance()->set( "Views.Path", null );
		Registry::getInstance()->set( "ViewCache", null );
	}

	public function testRender()
	{
		$Base = new Base( new Router() );

		Registry::getInstance()->set( "Views.Path", "examples/views" );

		$Result = $Base->renderMarkdown(
			HttpResponseStatus::OK,
			[
				'var_one' => 'test variable',
				'two'     => 2,
				'three'   => 3
			]
		);

		$this->assertStringContainsString( "<h2>Header 2</h2>", $Result );
		$this->assertStringContainsString( "This is a test.", $Result );
	}
}
