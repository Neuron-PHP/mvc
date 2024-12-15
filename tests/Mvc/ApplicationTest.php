<?php

namespace Mvc;

use Neuron\Core\CrossCutting\Event;
use Neuron\Data\Setting\Source\Ini;
use Neuron\Events\IEvent;
use Neuron\Events\IListener;
use Neuron\Log\Log;
use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\BadRequestMethodException;
use Neuron\Mvc\Controllers\IController;
use Neuron\Mvc\Controllers\NotFoundException;
use Neuron\Mvc\Events\Http404;
use Neuron\Mvc\Requests\Request;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use PHPUnit\Framework\TestCase;

class MockPhpInputStream
{
	private static $data;
	private $position;

	public function __construct()
	{
		$this->position = 0;
	}

	public static function setData($data)
	{
		self::$data = $data;
	}

	public function stream_open($path, $mode, $options, &$opened_path)
	{
		return true;
	}

	public function stream_read($count)
	{
		$result = substr(self::$data, $this->position, $count);
		$this->position += strlen($result);
		return $result;
	}

	public function stream_write($data)
	{
		if( self::$data === null )
		{
			self::$data = "";
		}

		$left = substr(self::$data, 0, $this->position);
		$right = substr(self::$data, $this->position + strlen($data));
		self::$data = $left . $data . $right;
		$this->position += strlen($data);
		return strlen($data);
	}

	public function stream_eof()
	{
		return $this->position >= strlen(self::$data);
	}

	public function stream_stat()
	{
		return [];
	}

	public function stream_seek($offset, $whence)
	{
		if ($whence === SEEK_SET) {
			$this->position = $offset;
			return true;
		}
		return false;
	}
}

function setInputStream($data)
{
	stream_wrapper_unregister("php");
	\Mvc\Requests\MockPhpInputStream::setData( $data);
	stream_wrapper_register("php", "Mvc\MockPhpInputStream");
	file_put_contents("php://input", $data);
}

function setHeaders( array $headers )
{
	foreach( $headers as $key => $value )
	{
		$name = 'HTTP_' . strtoupper( str_replace('-', '_', $key ) );
		$_SERVER[$name] = $value;
	}
}

$ControlledPassed = false;

class TestController implements IController
{
	public function test( array $Parameters, ?Request $Request )
	{
		Log::debug( "TestController::test" );

		global $ControlledPassed;
		$ControlledPassed = count( $Request->getErrors() ) === 0;
	}

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

	public function testRequestSuccess()
	{
		$this->assertNotNull( $this->App->getRequest( 'login' ) );
	}

	public function testRequestFail()
	{
		try
		{
			$this->assertNull( $this->App->getRequest( 'not-there' ) );
			$this->assertTrue( false );
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

		global $ControlledPassed;
		$ControlledPassed = false;

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

		global $ControlledPassed;
		$this->assertFalse( $ControlledPassed );
	}

	public function testControllerRequestSuccess()
	{
		setHeaders(
			[
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer some-token'
			]
		);

		global $ControlledPassed;
		$ControlledPassed = false;

		$Json = '
		{
			"param1": "test",
			"param2": "testtest",
			"param3": {
				"subparam1": "test",
				"subparam2": "test"
			}
		}';

		setInputStream( $Json );

		$this->App->run(
			[
				"type"  => "POST",
				"route" => "/test"
			]
		);

		global $ControlledPassed;
		$this->assertTrue( $ControlledPassed );
	}
}
