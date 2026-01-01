<?php
namespace Mvc;

use Neuron\Log\Log;
use Neuron\Mvc\IMvcApplication;
use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Controllers\IController;
use Neuron\Mvc\Requests\Request;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Routing\Router;

global $ControllerState;

class TestController extends Base
{
	public function test( Request $Request )
	{
		global $ControllerState;

		if( $Request )
			$ControllerState = count( $Request->getErrors() ) === 0;
		else
			$ControllerState = true;
	}

	public function partial( Request $Request ) : string
	{
		return $this->renderHtml(
			HttpResponseStatus::OK,
			[],
			"partial-test",
			"default",
			false
		);
	}

	public function no_request( Request $Request )
	{
		global $ControllerState;
		$ControllerState = true;
	}


	public function __construct( IMvcApplication $app )
	{
		parent::__construct( $app );
	}

	public function renderJson( HttpResponseStatus $ResponseCode, array $Data = [] ): string
	{
	}

	public function renderXml( HttpResponseStatus $ResponseCode, array $Data = [] ): string
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
