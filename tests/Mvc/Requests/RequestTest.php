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

		$this->assertIsArray(
			$Request->getParameters()
		);

		$this->assertArrayHasKey(
			'username',
			$Request->getParameters()
		);

		$this->assertArrayHasKey(
			'password',
			$Request->getParameters()
		);

		$this->assertArrayHasKey(
			'username',
			$Request->getParameters()
		);

		$Address = $Request->getParameter( 'address' )->getValue();

		$this->assertNotNull( $Address );

		$this->assertArrayHasKey(
			'street',
			$Address->getParameters()
		);
	}

	public function testProcessPayloadSuccess()
	{
		$Request = new Request();
		$Request->loadFile( 'examples/config/requests/login.yaml' );

		$Payload = [
			'username' => 'test',
			'password' => 'testtest',
			'age'      => 40,
			'birthday' => '1978-01-01',
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

		$this->assertEquals(
			'test',
			$Request->getParameter( 'username' )->getValue()
		);

		$this->assertEquals(
			'testtest',
			$Request->getParameter( 'password' )->getValue()
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
			'birthday' => '1978-01-01',
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

		$this->assertEquals(
			'test',
			$Request->getParameter( 'username' )->getValue()
		);

		$this->assertEquals(
			'testtest',
			$Request->getParameter( 'password' )->getValue()
		);
	}

	public function testRequestPayload()
	{
		$Request = new Request();
		$Request->loadFile( 'examples/config/requests/login.yaml' );

		$Json = '
		{
			"username": "test",
			"password": "testtest",
			"age": 40,
			"birthday": "1978-01-01",
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
