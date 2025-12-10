<?php

namespace Mvc\Controllers;

use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\Base;
use Neuron\Routing\RequestMethod;
use PHPUnit\Framework\TestCase;

class ControllerTest extends Base
{
	public function index()
	{
	}

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


class BaseTest extends TestCase
{

	public function testRegister()
	{
		$App = new Application( "1" );

		ControllerTest::register( $App, "controller" );

		// index
		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller" ) != null
		);

		// new

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller/new" ) != null
		);

		// show

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller/1" ) != null
		);

		// create

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::POST, "/controller/create" ) != null
		);

		// edit

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller/edit/1" ) != null
		);

		// update

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::POST, "/controller/1" ) != null
		);

		// delete

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller/delete/1" ) != null
		);
	}

	public function testUrlForWithRouter()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Add a named route
		$route = $App->getRouter()->get( '/users/:id', function() {} );
		$route->setName( 'user_profile' );

		// Use reflection to test protected method
		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'urlFor' );
		$method->setAccessible( true );

		$url = $method->invoke( $Controller, 'user_profile', ['id' => 123] );

		$this->assertEquals( '/users/123', $url );
	}

	public function testUrlForWithFallback()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'urlFor' );
		$method->setAccessible( true );

		// Test with nonexistent route and fallback
		$url = $method->invoke( $Controller, 'nonexistent', [], '/fallback' );

		$this->assertEquals( '/fallback', $url );
	}

	public function testUrlForAbsolute()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		\Neuron\Patterns\Registry::getInstance()->set( 'Base.Url', 'https://example.com' );

		// Add a named route
		$route = $App->getRouter()->get( '/users/:id', function() {} );
		$route->setName( 'user_profile' );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'urlForAbsolute' );
		$method->setAccessible( true );

		$url = $method->invoke( $Controller, 'user_profile', ['id' => 123] );

		$this->assertEquals( 'https://example.com/users/123', $url );
	}

	public function testUrlHelper()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'urlHelper' );
		$method->setAccessible( true );

		$helper = $method->invoke( $Controller );

		$this->assertInstanceOf( \Neuron\Mvc\Helpers\UrlHelper::class, $helper );
	}

	public function testRouteExists()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Add a named route
		$route = $App->getRouter()->get( '/test', function() {} );
		$route->setName( 'test_route' );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'routeExists' );
		$method->setAccessible( true );

		$exists = $method->invoke( $Controller, 'test_route' );
		$this->assertTrue( $exists );

		$notExists = $method->invoke( $Controller, 'nonexistent' );
		$this->assertFalse( $notExists );
	}

	public function testMagicCallForPathMethod()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Add a named route
		$route = $App->getRouter()->get( '/users/:id', function() {} );
		$route->setName( 'user_profile' );

		// Call magic method userProfilePath
		$url = $Controller->userProfilePath( ['id' => 456] );

		$this->assertEquals( '/users/456', $url );
	}

	public function testMagicCallForUrlMethod()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		\Neuron\Patterns\Registry::getInstance()->set( 'Base.Url', 'https://test.com' );

		// Add a named route
		$route = $App->getRouter()->get( '/users/:id', function() {} );
		$route->setName( 'user_profile' );

		// Call magic method userProfileUrl
		$url = $Controller->userProfileUrl( ['id' => 456] );

		$this->assertEquals( 'https://test.com/users/456', $url );
	}

	public function testMagicCallThrowsExceptionForInvalidMethod()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$this->expectException( \BadMethodCallException::class );

		$Controller->invalidMethodName();
	}

	public function testGetControllerName()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'getControllerName' );
		$method->setAccessible( true );

		$name = $method->invoke( $Controller );

		$this->assertEquals( 'ControllerTest', $name );
	}

	public function testGetControllerViewPath()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'getControllerViewPath' );
		$method->setAccessible( true );

		$path = $method->invoke( $Controller );

		// Should convert ControllerTest to controller_test
		$this->assertIsString( $path );
	}

	public function testInjectHelpersWithoutRegistry()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'injectHelpers' );
		$method->setAccessible( true );

		$data = ['key' => 'value'];
		$result = $method->invoke( $Controller, $data );

		$this->assertArrayHasKey( 'key', $result );
		$this->assertEquals( 'value', $result['key'] );
		// Should have urlHelper injected
		$this->assertArrayHasKey( 'urlHelper', $result );
		$this->assertInstanceOf( \Neuron\Mvc\Helpers\UrlHelper::class, $result['urlHelper'] );
	}

	public function testRenderJson()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$data = ['message' => 'success', 'count' => 42];
		$json = $Controller->renderJson( \Neuron\Mvc\Responses\HttpResponseStatus::OK, $data );

		$this->assertIsString( $json );
		$this->assertJson( $json );

		$decoded = json_decode( $json, true );
		$this->assertEquals( 'success', $decoded['message'] );
		$this->assertEquals( 42, $decoded['count'] );
	}

	public function testRenderXml()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$data = ['item' => ['name' => 'test', 'value' => 123]];
		$xml = $Controller->renderXml( \Neuron\Mvc\Responses\HttpResponseStatus::OK, $data );

		// Xml view is a placeholder that returns empty string
		$this->assertIsString( $xml );
		$this->assertEquals( '', $xml );
	}

	public function testInitializeViewCacheWithoutSettings()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Clear Registry to simulate no settings
		\Neuron\Patterns\Registry::getInstance()->set( 'Settings', null );
		\Neuron\Patterns\Registry::getInstance()->set( 'ViewCache', null );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'initializeViewCache' );
		$method->setAccessible( true );

		$result = $method->invoke( $Controller );

		$this->assertNull( $result );
	}

	public function testHasViewCacheWithoutCache()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Clear ViewCache from Registry
		\Neuron\Patterns\Registry::getInstance()->set( 'ViewCache', null );
		\Neuron\Patterns\Registry::getInstance()->set( 'Settings', null );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'hasViewCache' );
		$method->setAccessible( true );

		$result = $method->invoke( $Controller, 'index', [] );

		$this->assertFalse( $result );
	}

	public function testGetViewCacheWithoutCache()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Clear ViewCache from Registry
		\Neuron\Patterns\Registry::getInstance()->set( 'ViewCache', null );
		\Neuron\Patterns\Registry::getInstance()->set( 'Settings', null );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'getViewCache' );
		$method->setAccessible( true );

		$result = $method->invoke( $Controller, 'index', [] );

		$this->assertNull( $result );
	}

	public function testIsCacheEnabledByDefault()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Clear cache from Registry
		\Neuron\Patterns\Registry::getInstance()->set( 'ViewCache', null );
		\Neuron\Patterns\Registry::getInstance()->set( 'Settings', null );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'isCacheEnabledByDefault' );
		$method->setAccessible( true );

		$result = $method->invoke( $Controller );

		// Should be false when no cache is initialized
		$this->assertFalse( $result );
	}

	public function testHasViewCacheByKeyWithoutCache()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Clear ViewCache from Registry
		\Neuron\Patterns\Registry::getInstance()->set( 'ViewCache', null );
		\Neuron\Patterns\Registry::getInstance()->set( 'Settings', null );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'hasViewCacheByKey' );
		$method->setAccessible( true );

		$result = $method->invoke( $Controller, 'index', ['id' => 1] );

		$this->assertFalse( $result );
	}

	public function testGetViewCacheByKeyWithoutCache()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		// Clear ViewCache from Registry
		\Neuron\Patterns\Registry::getInstance()->set( 'ViewCache', null );
		\Neuron\Patterns\Registry::getInstance()->set( 'Settings', null );

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'getViewCacheByKey' );
		$method->setAccessible( true );

		$result = $method->invoke( $Controller, 'index', ['id' => 1] );

		$this->assertNull( $result );
	}

	public function testGetAndSetRouter()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$Router = new \Neuron\Routing\Router();
		$result = $Controller->setRouter( $Router );

		// Fluent interface - should return $this
		$this->assertSame( $Controller, $result );

		// Verify router was set
		$this->assertSame( $Router, $Controller->getRouter() );
	}

	public function testGetAndSetApplication()
	{
		$Controller = new ControllerTest();

		$App = new Application( "test-app" );
		$result = $Controller->setApplication( $App );

		// Fluent interface - should return $this
		$this->assertSame( $Controller, $result );

		// Verify application was set
		$this->assertSame( $App, $Controller->getApplication() );
	}

	public function testConstructorWithApplication()
	{
		$App = new Application( "test" );
		$Controller = new ControllerTest( $App );

		$this->assertSame( $App, $Controller->getApplication() );
		$this->assertSame( $App->getRouter(), $Controller->getRouter() );
	}

	public function testConstructorWithoutApplication()
	{
		$Controller = new ControllerTest();

		// Should not throw exception
		$this->assertInstanceOf( ControllerTest::class, $Controller );
	}

	public function testUrlForWithoutRouter()
	{
		$Controller = new ControllerTest();

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'urlFor' );
		$method->setAccessible( true );

		// Without router, should return fallback
		$result = $method->invoke( $Controller, 'test_route', [], '/fallback' );
		$this->assertEquals( '/fallback', $result );

		// Without fallback, should return null
		$result = $method->invoke( $Controller, 'test_route', [] );
		$this->assertNull( $result );
	}

	public function testUrlForAbsoluteWithoutRouter()
	{
		$Controller = new ControllerTest();

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'urlForAbsolute' );
		$method->setAccessible( true );

		// Without router, should return fallback
		$result = $method->invoke( $Controller, 'test_route', [], '/fallback' );
		$this->assertEquals( '/fallback', $result );
	}

	public function testUrlHelperWithoutRouter()
	{
		$Controller = new ControllerTest();

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'urlHelper' );
		$method->setAccessible( true );

		// Without router, should return null
		$result = $method->invoke( $Controller );
		$this->assertNull( $result );
	}

	public function testRouteExistsWithoutRouter()
	{
		$Controller = new ControllerTest();

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'routeExists' );
		$method->setAccessible( true );

		// Without router, should return false
		$result = $method->invoke( $Controller, 'test_route' );
		$this->assertFalse( $result );
	}

	public function testInjectHelpersWithoutRouter()
	{
		$Controller = new ControllerTest();

		$reflection = new \ReflectionClass( $Controller );
		$method = $reflection->getMethod( 'injectHelpers' );
		$method->setAccessible( true );

		$data = ['key' => 'value'];
		$result = $method->invoke( $Controller, $data );

		$this->assertArrayHasKey( 'key', $result );
		$this->assertEquals( 'value', $result['key'] );
		// Should NOT have urlHelper injected when router is not set
		$this->assertArrayNotHasKey( 'urlHelper', $result );
	}
}
