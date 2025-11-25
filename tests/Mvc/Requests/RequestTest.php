<?php

namespace Mvc\Requests;

use Neuron\Core\Exceptions\Validation;
use Neuron\Mvc\Requests\Request;
use Neuron\Routing\RequestMethod;
use PHPUnit\Framework\TestCase;

require_once 'tests/Mvc/HelperFunctions.php';

class RequestTest extends TestCase
{
	public function testRequest()
	{
		$Request = new Request();
		$Request->loadFile( 'examples/config/requests/login.yaml' );

		$this->assertTrue(
			$Request->getRequestMethod() === RequestMethod::POST
		);

		$this->assertIsArray(
			$Request->getHeaders()
		);

		$Dto = $Request->getDto();

		$this->assertNotNull( $Dto );

		$this->assertArrayHasKey(
			'username',
			$Dto->getProperties()
		);

		$this->assertArrayHasKey(
			'password',
			$Dto->getProperties()
		);

		$this->assertArrayHasKey(
			'address',
			$Dto->getProperties()
		);

		$AddressProperty = $Dto->getProperty( 'address' );

		$this->assertNotNull( $AddressProperty );

		$AddressDto = $AddressProperty->getValue();

		$this->assertArrayHasKey(
			'street',
			$AddressDto->getProperties()
		);
	}

	public function testProcessPayloadSuccess()
	{
		// Set required headers for the request
		setHeaders(
			[
				'Content-Type' => 'application/json'
			]
		);

		$Request = new Request();
		$Request->loadFile( 'examples/config/requests/login.yaml' );

		$Payload = [
			'username' => 'test',
			'password' => 'testtest',
			'age'      => 40,
			'birthdate' => '1978-01-01',
			'address'  => [
				'street' => '13 Mocking',
				'city'   => 'Mockingbird Heights',
				'state'  => 'CA',
				'zip'    => '90210'
			]
		];

		$Errors = [];

		try
		{
			$Request->processPayload( $Payload );
		}
		catch( Validation $Exception )
		{
			$Errors = $Exception->errors;
		}

		$this->assertEmpty( $Errors );

		$Dto = $Request->getDto();

		$this->assertEquals(
			'test',
			$Dto->username
		);

		$this->assertEquals(
			'testtest',
			$Dto->password
		);
	}

	public function testProcessPayloadFail()
	{
		$Request = new Request();
		$Request->loadFile( 'examples/config/requests/login.yaml' );

		$Payload = [
			'username' => 'test',
			'password' => 'testtest',
			'age'      => 42,
			'birthdate' => '1978-01-01',
			'address'  => [
				'street' => '13 Mockingbird Lane.',
				'city'   => 'Mockingbird Heights',
				'state'  => 'CA'
			]
		];

		$Errors = [];

		try
		{
			$Request->processPayload( $Payload );
		}
		catch( Validation $Exception )
		{
			$Errors = $Exception->errors;
		}

		$this->assertNotEmpty( $Errors );

		$Dto = $Request->getDto();

		$this->assertEquals(
			'test',
			$Dto->username
		);

		$this->assertEquals(
			'testtest',
			$Dto->password
		);
	}

	public function testRequestPayload()
	{
		// Set required headers for the request
		setHeaders(
			[
				'Content-Type' => 'application/json'
			]
		);

		$Request = new Request();
		$Request->loadFile( 'examples/config/requests/login.yaml' );

		$Json = '
		{
			"username": "test",
			"password": "testtest",
			"age": 40,
			"birthdate": "1978-01-01",
			"address": {
				"street": "13 Mocking",
				"city": "Mockingbird Heights",
				"state": "CA",
				"zip": "90210"
			}
		}';

		setInputStream( $Json );

		$Errors = [];

		try
		{
			$Request->processPayload( $Request->getJsonPayload() );
		}
		catch( Validation $Exception )
		{
			$Errors = $Exception->errors;
		}

		$this->assertEmpty( $Errors );
	}

	public function testNoProperties()
	{
		// Set required headers for the request
		setHeaders(
			[
				'Content-Type' => 'application/json'
			]
		);

		$Request = new Request();
		$Request->loadFile( 'examples/config/requests/logout.yaml' );

		$Json = '{}';

		setInputStream( $Json );

		$Errors = [];

		try
		{
			$Request->processPayload( $Request->getJsonPayload() );
		}
		catch( Validation $Exception )
		{
			$Errors = $Exception->errors;
		}

		$this->assertEmpty( $Errors );
	}

	public function testSetAndGetRouteParameters()
	{
		$Request = new Request();

		$Parameters = [
			'id' => '123',
			'slug' => 'test-post',
			'action' => 'edit'
		];

		$Request->setRouteParameters( $Parameters );

		$this->assertEquals(
			$Parameters,
			$Request->getRouteParameters()
		);
	}

	public function testGetRouteParameter()
	{
		$Request = new Request();

		$Parameters = [
			'id' => '456',
			'category' => 'news'
		];

		$Request->setRouteParameters( $Parameters );

		$this->assertEquals(
			'456',
			$Request->getRouteParameter( 'id' )
		);

		$this->assertEquals(
			'news',
			$Request->getRouteParameter( 'category' )
		);
	}

	public function testGetRouteParameterReturnsNull()
	{
		$Request = new Request();

		$Parameters = [
			'id' => '789'
		];

		$Request->setRouteParameters( $Parameters );

		$this->assertNull(
			$Request->getRouteParameter( 'nonexistent' )
		);
	}

	public function testGetClientIp()
	{
		$_SERVER['REMOTE_ADDR'] = '192.168.1.100';

		$Request = new Request();
		$Ip = $Request->getClientIp();

		$this->assertEquals( '192.168.1.100', $Ip );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function testGetClientIpWithProxyHeaders()
	{
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.42';
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

		$Request = new Request();
		$Ip = $Request->getClientIp();

		$this->assertEquals( '203.0.113.42', $Ip );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function testGetClientIpWithCloudflare()
	{
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.100';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '172.71.255.1';
		$_SERVER['REMOTE_ADDR'] = '172.71.255.1';

		$Request = new Request();
		$Ip = $Request->getClientIp();

		// DefaultIpResolver prioritizes CF-Connecting-IP
		$this->assertEquals( '203.0.113.100', $Ip );

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * Note: The get(), post(), server(), session(), and cookie() methods use
	 * PHP's filter_input() function internally through the static Filter classes.
	 * These cannot be reliably unit tested because filter_input() reads from
	 * PHP's input filter extension, not from the superglobal arrays ($_GET, $_POST, etc.).
	 *
	 * The filter_input() values are set when PHP starts processing the request,
	 * before any code runs, so setting $_GET['key'] = 'value' in a test doesn't
	 * affect filter_input(INPUT_GET, 'key').
	 *
	 * These methods should be tested through integration tests with actual HTTP requests.
	 * The static method refactoring (from instance properties to static calls) doesn't
	 * change the functionality - it's just a cleaner implementation.
	 */
	public function testFilterMethodsWithDefaults()
	{
		$Request = new Request();

		// Test that default values work when no input is available
		$this->assertEquals( 'default_get', $Request->get( 'nonexistent', 'default_get' ) );
		$this->assertEquals( 'default_post', $Request->post( 'nonexistent', 'default_post' ) );
		$this->assertEquals( 'default_server', $Request->server( 'NONEXISTENT', 'default_server' ) );
		$this->assertEquals( 'default_session', $Request->session( 'nonexistent', 'default_session' ) );
		$this->assertEquals( 'default_cookie', $Request->cookie( 'nonexistent', 'default_cookie' ) );
	}
}
