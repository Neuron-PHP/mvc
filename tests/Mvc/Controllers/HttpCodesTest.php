<?php

namespace Tests\Mvc\Controllers;

use Neuron\Mvc\Controllers\HttpCodes;
use Neuron\Mvc\IMvcApplication;
use Neuron\Mvc\Requests\Request;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use PHPUnit\Framework\TestCase;

class HttpCodesTest extends TestCase
{
	private HttpCodes $controller;
	private IMvcApplication $MockApp;

	protected function setUp(): void
	{
		parent::setUp();

		// Set up the base path for views
		Registry::getInstance()->set( 'Base.Path', dirname( __DIR__, 3 ) );

		// Create mock application
		$router = $this->createMock( Router::class );
		$this->MockApp = $this->createMock( IMvcApplication::class );
		$this->MockApp->method( 'getRouter' )->willReturn( $router );

		$this->controller = new HttpCodes( $this->MockApp );
	}

	protected function tearDown(): void
	{
		// Clean up registry
		Registry::getInstance()->set( 'Base.Path', null );

		parent::tearDown();
	}

	public function testCode404(): void
	{
		// Create a mock Request
		$request = $this->createMock( Request::class );
		$request->method( 'getRouteParameters' )
			->willReturn( ['route' => '/test/path'] );

		$result = $this->controller->code404( $request );

		// Verify result is a string (rendered HTML)
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function testCode404WithEmptyRouteParameters(): void
	{
		$request = $this->createMock( Request::class );
		$request->method( 'getRouteParameters' )
			->willReturn( [] );

		$result = $this->controller->code404( $request );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function testCode500(): void
	{
		// Create a mock Request
		$request = $this->createMock( Request::class );
		$request->method( 'getRouteParameters' )
			->willReturn( ['route' => '/error/path'] );
		$request->method( 'getRouteParameter' )
			->with( 'error' )
			->willReturn( 'Test error message' );

		$result = $this->controller->code500( $request );

		// Verify result is a string (rendered HTML)
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function testCode500WithNullError(): void
	{
		$request = $this->createMock( Request::class );
		$request->method( 'getRouteParameters' )
			->willReturn( [] );
		$request->method( 'getRouteParameter' )
			->with( 'error' )
			->willReturn( null );

		$result = $this->controller->code500( $request );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function testCode500WithDifferentErrors(): void
	{
		$errorMessages = [
			'Database connection failed',
			'File not found',
			'Permission denied',
			'Timeout error'
		];

		foreach( $errorMessages as $errorMsg )
		{
			$request = $this->createMock( Request::class );
			$request->method( 'getRouteParameters' )
				->willReturn( [] );
			$request->method( 'getRouteParameter' )
				->with( 'error' )
				->willReturn( $errorMsg );

			$result = $this->controller->code500( $request );

			$this->assertIsString( $result );
			$this->assertNotEmpty( $result );
		}
	}
}
