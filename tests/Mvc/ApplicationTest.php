<?php

namespace Mvc;

use Neuron\Core\CrossCutting\Event;
use Neuron\Data\Setting\Source\Ini;
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
	public Application $App;

	protected function setUp() : void
	{
		parent::setUp();

		$Ini = new Ini( './examples/application.ini' );
		$this->App = new Application( "1.0.0", $Ini );
	}

	public function testConfig()
	{
		$this->App->run(
			[
				"type"  => "GET",
				"route" => "/test"
			]
		);

		$this->assertEquals(
			"1.0.0",
			$this->App->getVersion()
		);

		$this->assertEquals(
			"examples/views",
			Registry::getInstance()->get( "Views.Path" )
		);
	}

	public function testHtml()
	{
		ob_start();

		$this->App->run(
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

		$this->assertEquals(
			$Version,
			$this->App->getVersion()
		);
	}

	public function testAddRouteFails()
	{
		$Success = true;

		try
		{
			$this->App->addRoute( 'poop', '/test', 'Test' );
		}
		catch( BadRequestMethodException $Exception )
		{
			$Success = false;
		}

		$this->assertFalse( $Success );
	}

	public function testMissingController()
	{
		$Success = true;

		Registry::getInstance()->set( "Controllers.NameSpace", "Mvc" );
		try
		{
			$this->App->addRoute( 'GET', '/test', 'TestController@testMethod' );

			$this->App->run(
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

	public function test404Event()
	{
		$this->App->initEvents();

		$Http = new Http404ListenerTest();

		Event::registerListeners(
			[
				Http404::class => [
					$Http
				]
			]
		);

		$this->App->run(
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
