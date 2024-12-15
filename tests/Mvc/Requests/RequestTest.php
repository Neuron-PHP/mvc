<?php

namespace Mvc\Requests;

use Neuron\Mvc\Requests\ValidationException;
use Neuron\Routing\RequestMethod;
use PHPUnit\Framework\TestCase;
use Neuron\Mvc\Requests\Request;

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
	stream_wrapper_register("php", "Mvc\Requests\MockPhpInputStream");
	file_put_contents("php://input", $data);
}

class RequestTest extends TestCase
{
	public function testRequest()
	{
		$Request = new Request( 'login' );
		$Request->loadFile( 'resources/Requests/login.yaml' );

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
		$Request->loadFile( 'resources/Requests/login.yaml' );

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
		catch( ValidationException $Exception )
		{
			$Errors = $Exception->getErrors();
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
		$Request->loadFile( 'resources/Requests/login.yaml' );

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
		catch( ValidationException $Exception )
		{
			$Errors = $Exception->getErrors();
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
		$Request->loadFile( 'resources/Requests/login.yaml' );

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
		catch( ValidationException $Exception )
		{
			$Errors = $Exception->getErrors();
		}

		$this->assertEmpty( $Errors );
	}

}
