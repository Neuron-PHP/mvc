<?php

namespace Mvc;

use Neuron\Core\CrossCutting\Event;
use Neuron\Events\IEvent;
use Neuron\Events\IListener;
use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\BadRequestMethodException;
use Neuron\Mvc\Controllers\IController;
use Neuron\Mvc\Controllers\NotFoundException;
use Neuron\Mvc\Events\Http404;
use Neuron\Patterns\Registry;
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
	}

	public function renderJson( array $Data = [] ): string
	{
	}

	public function renderXml( array $Data = [] ): string
	{
	}
}

class Http404ListenerTest implements IListener
{
	public string $State;

	public function event( $Event )
	{
		$this->State = get_class( $Event );
	}
}

class ApplicationTest extends TestCase
{
	protected function setUp() : void
	{
		parent::setUp();

		Registry::getInstance()
							 ->set( "Views.Path", "./resources/views" );

	}

	public function testHtml()
	{
		$App = new Application( "" );

		$App->initEvents();

		Event::registerListeners(
			[
				Http404::class => [
					Http404ListenerTest::class
				]
			]
		);

		ob_start();

		$App->run(
			[
				"type"  => "GET",
				"route" => "/test404"
			]
		);

		$Output = ob_get_clean();

		$this->assertStringContainsString(
			"<html>",
			$Output
		);

		$this->assertStringContainsString(
			"Resource Not Found",
			$Output
		);

		$this->assertStringContainsString(
			"does not exist",
			$Output
		);
	}

	public function testGetVersion()
	{
		$Version = "1.0.0";

		$App = new Application( $Version );

		$this->assertEquals(
			$Version,
			$App->getVersion()
		);
	}

	public function testAddRouteFails()
	{
		$App = new Application( "" );

		$Success = true;

		try
		{
			$App->addRoute( 'poop', '/test', 'Test' );
		}
		catch( BadRequestMethodException $Exception )
		{
			$Success = false;
		}

		$this->assertFalse( $Success );
	}

	public function testDispatchFails()
	{
		$App = new Application( "" );

		$Success = true;

		Registry::getInstance()->set( "Controllers.NameSpace", "Mvc" );
		try
		{
			$App->addRoute( 'GET', '/test', 'TestController@testMethod' );

			$App->run(
				[
					"type"  => "GET",
					"route" => "/test"
				]
			);
		}
		catch( NotFoundException $Exception )
		{
			$Success = false;
		}

		$this->assertTrue( $Success );
	}

	public function test404()
	{
		$App = new Application( "" );

		$App->initEvents();

		$Http = new Http404ListenerTest();

		Event::registerListeners(
			[
				Http404::class => [
					$Http
				]
			]
		);

		$App->run(
			[
				"type"  => "GET",
				"route" => "/test404"
			]
		);

		$this->assertEquals(
			Http404::class,
			$Http->State
		);
	}
}
