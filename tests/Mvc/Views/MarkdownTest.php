<?php

namespace Mvc\Views;

use Neuron\Mvc\Controllers\Base;
use Neuron\Routing\Router;
use PHPUnit\Framework\TestCase;

class MarkdownTest extends TestCase
{
	public function testRender()
	{
		$Base = new Base( new Router() );

		$Result = $Base->renderMarkdown(
			200,
			[
				'var_one' => 'test variable',
				'two'     => 2,
				'three'   => 3
			]
		);

		$this->assertStringContainsString( "<h2>Header 2</h2>", $Result );
	}
}
