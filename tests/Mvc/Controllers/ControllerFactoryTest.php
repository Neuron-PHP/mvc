<?php

namespace Mvc\Controllers;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\Factory;
use Neuron\Mvc\Controllers\IController;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Routing\Router;
use PHPUnit\Framework\TestCase;

class TestController implements IController
{
	private Application $_app;
	
	public function __construct( ?Application $app )
	{
		$this->_app = $app;
	}
	
	public function getRouter(): Router
	{
		return $this->_app->getRouter();
	}
	
	public function renderHtml( HttpResponseStatus $ResponseCode, array $Data = [], string $Page = "index", string $Layout = "default", ?bool $CacheEnabled = null ): string
	{
		return 'html';
	}
	
	public function renderJson( HttpResponseStatus $ResponseCode, array $Data = [] ): string
	{
		return 'json';
	}
	
	public function renderXml( HttpResponseStatus $ResponseCode, array $Data = [] ): string
	{
		return 'xml';
	}

	public function renderMarkdown( HttpResponseStatus $ResponseCode, array $Data = [], string $Page = "index", string $Layout = "default", ?bool $CacheEnabled = null ): string
	{
		return 'markdown';
	}
}

class InvalidController
{
	public function __construct( Router $Router )
	{
		// This class does not implement IController
	}
}

class ControllerFactoryTest extends TestCase
{
	private Application $App;
	
	protected function setUp(): void
	{
		$this->App = $this->createMock( Application::class );
		$this->App->method( 'getRouter' )->willReturn( new Router() );
	}
	
	public function testCreateValidController()
	{
		$Controller = Factory::create( $this->App, TestController::class );
		
		$this->assertInstanceOf( IController::class, $Controller );
		$this->assertInstanceOf( TestController::class, $Controller );
		$this->assertInstanceOf( Router::class, $Controller->getRouter() );
	}
	
	public function testCreateNonExistentController()
	{
		$this->expectException( NotFound::class );
		$this->expectExceptionMessage( 'Controller NonExistentController not found.' );
		
		Factory::create( $this->App, 'NonExistentController' );
	}
	
	public function testCreateControllerNotImplementingInterface()
	{
		$this->expectException( NotFound::class );
		$this->expectExceptionMessage( InvalidController::class . ' does not implement IController.' );
		
		Factory::create( $this->App, InvalidController::class );
	}
}
