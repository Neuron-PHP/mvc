<?php

namespace Mvc;

use Neuron\Core\CrossCutting\Event;
use Neuron\Data\Setting\Source\Ini;
use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\BadRequestMethodException;
use Neuron\Mvc\Controllers\NotFoundException;
use Neuron\Mvc\Events\Http404;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

$ControllerState = false;

class ApplicationTest extends TestCase
{
	public Application $App;

	/**
	 * @throws \Exception
	 */
	protected function setUp() : void
	{
		parent::setUp();

		$Ini = new Ini( './examples/config/config.ini' );
		$this->App = new Application( "1.0.0", $Ini );
	}

	/**
	 * @throws \Exception
	 */
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

	/**
	 * @throws \Exception
	 */
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

		$Http = new Http404Listener();

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

	public function testRequestSuccess()
	{
		$this->assertNotNull( $this->App->getRequest( 'login' ) );
	}

	public function testRequestFail()
	{
		try
		{
			$this->assertNull( $this->App->getRequest( 'not-there' ) );
			$this->fail();
		}
		catch( \Exception $Exception )
		{
			$this->assertTrue( true );
		}
	}

	public function testControllerRequestFailed()
	{
		setHeaders(
			[
				'Content-Type'		=> 'application/xml',
				'Authorization'	=> 'Bearer some-token'
			]
		);

		global $ControllerState;
		$ControllerState = false;

		$Json = '
		{
			"param1": "test",
			"param2": "testtest"
		}';

		setInputStream( $Json );

		$this->App->run(
			[
				"type"  => "POST",
				"route" => "/test"
			]
		);

		global $ControllerState;
		$this->assertFalse( $ControllerState );
	}

	public function testControllerRequestSuccess()
	{
		setHeaders(
			[
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer some-token'
			]
		);

		global $ControllerState;
		$ControllerState = false;

		$Json = '
		{
			"array": 
			[
				"test", 
				"test"
			],
			"boolean": true,
			"date": "2020-01-01",
			"date_time": "2020-01-01 12:00:00",
			"ein": "12-3456789",
			"email": "test@test.com",
			"float": 1.23,
			"integer": 123,
			"ip_address": "192.168.1.1",
			"name": "Testy McTestface",
			"numeric": 123,
			"object": {
				"subparam1": "test",
				"subparam2": "test"
			},
			"string": "test",
			"time": "12:00:00 PM",
			"upc": "123456789012",
			"url": "http://www.test.com",
			"us_phone": "555-555-5555",
			"intl_phone": "+49 89 636 48098"
		}';

		setInputStream( $Json );

		$this->App->run(
			[
				"type"  => "POST",
				"route" => "/test"
			]
		);

		global $ControllerState;
		$this->assertTrue( $ControllerState );
	}

	public function testControllerNoRequest()
	{
		global $ControllerState;
		$ControllerState = false;

		$this->App->run(
			[
				"type"  => "GET",
				"route" => "/no_request"
			]
		);

		$this->assertTrue( $ControllerState );
	}
}
