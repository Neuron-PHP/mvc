<?php

namespace Mvc\Views;

use Neuron\Mvc\Views\Json;
use PHPUnit\Framework\TestCase;

class JsonTest extends TestCase
{
	public function testRender()
	{
		$View = new Json();

		$this->assertNotNull(
			json_decode(
				$View->render(
					[
						'one'   => 1,
						'two'   => 2,
						'three' => 3
					]
				)
			)
		);
	}
}
