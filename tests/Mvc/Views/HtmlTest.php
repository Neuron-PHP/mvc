<?php

namespace Mvc\Views;

use Neuron\Mvc\Controllers\Base;
use Neuron\Routing\Router;
use PHPUnit\Framework\TestCase;

class HtmlTest extends TestCase
{
	public function testRender()
	{
		$Base = new Base( new Router() );

		$Result = $Base->renderHtml(
			200,
			[
				'var_one' => 'test variable',
				'two'     => 2,
				'three'   => 3
			]
		);

		$this->assertStringContainsString( "test variable", $Result );
	}
}
