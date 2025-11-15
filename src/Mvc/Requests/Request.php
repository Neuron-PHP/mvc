<?php

namespace Neuron\Mvc\Requests;

use Neuron\Core\Exceptions\Validation;
use Neuron\Data\Filter\Cookie;
use Neuron\Data\Filter\Get;
use Neuron\Data\Filter\Post;
use Neuron\Data\Filter\Server;
use Neuron\Data\Filter\Session;
use Neuron\Log\Log;
use Neuron\Routing\DefaultIpResolver;
use Neuron\Routing\RequestMethod;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Request
 */
class Request
{
	private string $_name = '';
	private int   $_requestMethod;
	private array $_headers = [];
	private array $_parameters = [];
	private array $_routeParameters = [];
	private array $_errors = [];
	private DefaultIpResolver $_ipResolver;
	private Cookie $_cookie;
	private Get $_get;
	private Post $_post;
	private Server $_server;
	private Session $_session;

	/**
	 * Request constructor.
	 */
	public function __construct()
	{
		$this->_ipResolver = new DefaultIpResolver();
		$this->_cookie = new Cookie();
		$this->_get = new Get();
		$this->_post = new Post();
		$this->_server = new Server();
		$this->_session = new Session();
	}

	/**
	 * Set route parameters
	 *
	 * @param array $routeParameters
	 * @return void
	 */
	public function setRouteParameters( array $routeParameters ): void
	{
		$this->_routeParameters = $routeParameters;
	}

	/**
	 * Get all route parameters
	 *
	 * @return array
	 */
	public function getRouteParameters(): array
	{
		return $this->_routeParameters;
	}

	/**
	 * Get a route parameter by key
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function getRouteParameter( string $key ): mixed
	{
		return $this->_routeParameters[ $key ] ?? null;
	}

	/**
	 * @return string
	 */
	public function getClientIp(): string
	{
		return $this->_ipResolver->resolve( $_SERVER );
	}

	/**
	 * Filtered GET parameter
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed
	{
		return $this->_get->filterScalar( $key, $default );
	}

	/**
	 * Filtered POST parameter
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function post( string $key, mixed $default = null ): mixed
	{
		return $this->_post->filterScalar( $key, $default );
	}

	/**
	 * Filtered SERVER parameter
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function server( string $key, mixed $default = null ): mixed
	{
		return $this->_server->filterScalar( $key, $default );
	}

	/**
	 *
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function session( string $key, mixed $default = null ): mixed
	{
		return $this->_session->filterScalar( $key, $default );
	}

	/**
	 * @return array
	 */
	public function getErrors(): array
	{
		return $this->_errors;
	}

	/**
	 * Returns an array representation of the JSON payload from php://input
	 * @return array
	 */
	public function getJsonPayload(): array
	{
		$input = file_get_contents( 'php://input' );
		$result = json_decode( $input, true ) ?? [];

		if( json_last_error() !== JSON_ERROR_NONE )
		{
			return [];
		}

		return $result;
	}

	/**
	 * Get the type of request. See RequestMethod class for possible values.
	 * @return int
	 */
	public function getRequestMethod(): int
	{
		return $this->_requestMethod;
	}

	/**
	 * @return array
	 */
	public function getHeaders(): array
	{
		return $this->_headers;
	}

	/**
	 * @return array
	 */
	public function getParameters(): array
	{
		return $this->_parameters;
	}

	/**
	 * @param string $name
	 * @return Parameter|null
	 */
	public function getParameter( string $name ): ?Parameter
	{
		return $this->_parameters[ $name ] ?? null;
	}

	/**
	 * @param string $fileName
	 * @return void
	 */
	public function loadFile( string $fileName ): void
	{
		$name = pathinfo( $fileName )[ 'filename' ];
		$data = Yaml::parseFile( $fileName );

		$this->_requestMethod = RequestMethod::getType( $data[ 'request' ][ 'method' ] );
		$this->_headers       = $data[ 'request' ][ 'headers' ];

		$this->loadData( $name, $data[ 'request' ] );
	}

	/**
	 * @param string $name
	 * @param array $request
	 */
	protected function loadData( string $name, array $request ) : void
	{
		$this->_name = $name;
		$this->_parameters = [];

		if( !isset( $request[ 'properties' ] ) )
		{
			return;
		}

		foreach( $request[ 'properties' ] as $name => $parameter )
		{
			$p = new Parameter();
			$p->setName( $name );

			if( isset( $parameter[ 'required' ] ) )
			{
				$p->setRequired( $parameter[ 'required' ] );
			}

			$p->setType( $parameter[ 'type' ] );

			if( $p->getType() === 'object' )
			{
				$request = new Request();
				$request->loadData( $this->_name.':'.$name, $parameter );
				$p->setValue( $request );
			}

			if( isset( $parameter[ 'minLength' ] ) )
			{
				$p->setMinLength( $parameter[ 'minLength' ] );
			}

			if( isset( $parameter[ 'maxLength' ] ) )
			{
				$p->setMaxLength( $parameter[ 'maxLength' ] );
			}

			if( isset( $parameter[ 'minimum' ] ) )
			{
				$p->setMinValue( $parameter[ 'minimum' ] );
			}

			if( isset( $parameter[ 'maximum' ] ) )
			{
				$p->setMaxValue( $parameter[ 'maximum' ] );
			}

			if( isset( $parameter[ 'pattern' ] ) )
			{
				$p->setPattern( $parameter[ 'pattern' ] );
			}

			$this->_parameters[ $p->getName() ] = $p;
		}
	}

	/**
	 * @return array
	 */
	public function getHttpHeaders(): array
	{
		if( function_exists('getallheaders') )
		{
			return getallheaders();
		}

		$headers = [];
		foreach( $_SERVER as $name => $value )
		{
			if( substr( $name, 0, 5 ) == 'HTTP_')
			{
				$headerName = str_replace('_', '-', substr( $name, 5 ) );
				$headers[ ucwords( strtolower( $headerName ), '-' ) ] = $value;
			}
		}

		return $headers;
	}

	/**
	 * @param array $payload
	 * @throws Validation
	 */
	public function processPayload( array $payload ): void
	{
		$this->_errors = [];

		$requiredHeaders = $this->_headers;

		$headers = $this->getHttpHeaders();

		foreach( $requiredHeaders as $requiredName => $requiredValue )
		{
			if( !array_key_exists( $requiredName, $headers ) )
			{
				$msg = 'Missing header: ' . $requiredName;
				Log::warning( $msg );

				$this->_errors[] = $msg;
				continue;
			}

			if( $headers[ $requiredName ] !== $requiredValue )
			{
				$msg = "Invalid header value: $requiredName, expected: $requiredValue, got: " . $headers[ $requiredName ];
				Log::warning( $msg );

				$this->_errors[] = $msg;
			}
		}

		foreach( $this->_parameters as $parameter )
		{
			if( !isset( $payload[ $parameter->getName() ] )  )
			{
				$this->validateParameter( $parameter );
				continue;
			}

			if( $parameter->getType() === 'object' )
			{
				try
				{
					$parameter->getValue()->processPayload( $payload[ $parameter->getName() ] );
					continue;
				}
				catch( Validation $exception )
				{
					Log::warning( $exception->getMessage() );
					$this->_errors = array_merge( $this->_errors, $exception->errors );
				}
			}

			$parameter->setValue( $payload[ $parameter->getName() ] );

			$this->validateParameter( $parameter );
		}

		if( !empty( $this->_errors ) )
		{
			throw new Validation( $this->_name, $this->_errors );
		}
	}

	/**
	 * @param mixed $parameter
	 */
	protected function validateParameter( mixed $parameter ): void
	{
		try
		{
			$parameter->validate();
		}
		catch( Validation $exception )
		{
			Log::warning( $exception->getMessage() );
			$this->_errors[] = $exception->getMessage();
		}
	}
}
