<?php

namespace Mvc\Controllers;

use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\Factory;
use Neuron\Mvc\Controllers\IController;
use Neuron\Routing\Router;
use PHPUnit\Framework\TestCase;

class TestController implements IController
{
	public function testMethod()
	{}

	public function __construct( Router $Router )
	{
	}

	public function renderHtml( array $Data = [], string $Page = "index", string $Layout = "default" ) : string
	{
		// TODO: Implement renderHtml() method.
	}

	public function renderJson( array $Data = [] ): string
	{
		// TODO: Implement renderJson() method.
	}

	public function renderXml( array $Data = [] ): string
	{
		// TODO: Implement renderXml() method.
	}
}

class FactoryTest extends TestCase
{
	public function testCreate()
	{
		$App = new Application( "1" );

		$Controller = Factory::create( $App, "Mvc\Controllers\TestController" );

		$this->assertTrue(
			$Controller instanceof TestController
		);
	}
}
