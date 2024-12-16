<?php

namespace Mvc\Controllers;

use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\Factory;
use PHPUnit\Framework\TestCase;
use Mvc\TestController;

class FactoryTest extends TestCase
{
	public function testCreate()
	{
		$App = new Application( "1" );

		$Controller = Factory::create( $App, "Mvc\TestController" );

		$this->assertTrue(
			$Controller instanceof TestController
		);
	}
}
