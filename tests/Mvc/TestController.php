<?php
namespace Mvc;

use Neuron\Log\Log;
use Neuron\Mvc\Controllers\IController;
use Neuron\Mvc\Requests\Request;
use Neuron\Routing\Router;

global $ControllerState;

class TestController implements IController
{
	public function test( array $Parameters, ?Request $Request )
	{
		global $ControllerState;
		$ControllerState = count( $Request->getErrors() ) === 0;
	}

	public function no_request( array $Parameters, ?Request $Request )
	{
		global $ControllerState;
		$ControllerState = $Request === null;
	}


	public function __construct( Router $Router )
	{
	}

	public function renderHtml( int $ResponseCode, array $Data = [], string $Page = "index", string $Layout = "default" ) : string
	{
	}

	public function renderJson( int $ResponseCode, array $Data = [] ): string
	{
	}

	public function renderXml( int $ResponseCode, array $Data = [] ): string
	{
	}

	public function index()
	{}

	public function add()
	{}

	public function show()
	{}

	public function create()
	{}

	public function edit()
	{}

	public function update()
	{}

	public function delete()
	{}
}
