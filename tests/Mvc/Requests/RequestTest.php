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
}
