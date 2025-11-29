<?php

namespace Neuron\Mvc\Requests;

use Neuron\Core\Exceptions\Validation;
use Neuron\Data\Filters\Cookie;
use Neuron\Data\Filters\Get;
use Neuron\Data\Filters\Post;
use Neuron\Data\Filters\Server;
use Neuron\Data\Filters\Session;
use Neuron\Dto\Dto;
use Neuron\Dto\Factory as DtoFactory;
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
	private ?Dto $_dto = null;
	private array $_routeParameters = [];
	private array $_errors = [];
	private DefaultIpResolver $_ipResolver;

	/**
	 * Request constructor.
	 */
	public function __construct()
	{
		$this->_ipResolver = new DefaultIpResolver();
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
		return Get::filterScalar( $key, $default );
	}

	/**
	 * Filtered POST parameter
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function post( string $key, mixed $default = null ): mixed
	{
		return Post::filterScalar( $key, $default );
	}

	/**
	 * Filtered SERVER parameter
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function server( string $key, mixed $default = null ): mixed
	{
		return Server::filterScalar( $key, $default );
	}

	/**
	 * Filtered SESSION parameter
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function session( string $key, mixed $default = null ): mixed
	{
		return Session::filterScalar( $key, $default );
	}

	/**
	 * Filtered COOKIE parameter
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function cookie( string $key, mixed $default = null ): mixed
	{
		return Cookie::filterScalar( $key, $default );
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
	 * Get the DTO instance
	 * @return Dto|null
	 */
	public function getDto(): ?Dto
	{
		return $this->_dto;
	}

	/**
	 * @param string $fileName
	 * @return void
	 * @throws \Exception
	 */
	public function loadFile( string $fileName ): void
	{
		$name = pathinfo( $fileName )[ 'filename' ];
		$data = Yaml::parseFile( $fileName );

		$this->_name = $name;
		$this->_requestMethod = RequestMethod::getType( $data[ 'request' ][ 'method' ] );
		$this->_headers       = $data[ 'request' ][ 'headers' ] ?? [];

		// Load DTO from either referenced file or inline properties
		if( isset( $data[ 'request' ][ 'dto' ] ) )
		{
			// Referenced DTO - resolve path and load
			$dtoPath = $this->resolveDtoPath( $data[ 'request' ][ 'dto' ] );
			$factory = new DtoFactory( $dtoPath );
			$this->_dto = $factory->create();
		}
		elseif( isset( $data[ 'request' ][ 'properties' ] ) )
		{
			// Inline DTO - create from array
			$factory = new DtoFactory( $data[ 'request' ][ 'properties' ] );
			$this->_dto = $factory->create();
		}
	}

	/**
	 * Resolve DTO file path from name
	 * Checks: absolute path, relative to current dir, common DTO locations
	 *
	 * @param string $name
	 * @return string
	 * @throws \Exception
	 */
	protected function resolveDtoPath( string $name ): string
	{
		// If already has .yaml extension, use as-is
		if( !str_ends_with( $name, '.yaml' ) )
		{
			$name .= '.yaml';
		}

		// Check if absolute path exists
		if( file_exists( $name ) )
		{
			return $name;
		}

		// Check common DTO locations
		$commonPaths = [
			getcwd() . '/Dtos/' . $name,
			getcwd() . '/src/Dtos/' . $name,
			getcwd() . '/../Dtos/' . $name,
		];

		foreach( $commonPaths as $path )
		{
			if( file_exists( $path ) )
			{
				return $path;
			}
		}

		// Not found - throw exception
		throw new \Exception( "DTO file not found: {$name}" );
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
	 * Process and validate payload data
	 * @param array $payload
	 * @throws Validation
	 */
	public function processPayload( array $payload ): void
	{
		$this->_errors = [];

		// Validate HTTP headers
		$this->validateHeaders();

		// Populate and validate DTO
		if( $this->_dto )
		{
			// Populate DTO properties from payload (handles nested objects)
			$this->populateDto( $this->_dto, $payload );

			// Validate entire DTO
			try
			{
				$this->_dto->validate();
			}
			catch( Validation $exception )
			{
				Log::warning( $exception->getMessage() );
				$this->_errors = array_merge( $this->_errors, $exception->errors );
			}
		}

		// Throw if any errors accumulated
		if( !empty( $this->_errors ) )
		{
			throw new Validation( $this->_name, $this->_errors );
		}
	}

	/**
	 * Recursively populate DTO from payload data
	 * @param Dto $dto
	 * @param array $data
	 * @return void
	 */
	protected function populateDto( Dto $dto, array $data ): void
	{
		foreach( $data as $key => $value )
		{
			try
			{
				$property = $dto->getProperty( $key );

				if( !$property )
				{
					continue;
				}

				// Handle nested objects
				if( $property->getType() === 'object' && is_array( $value ) )
				{
					$nestedDto = $property->getValue();
					if( $nestedDto instanceof Dto )
					{
						$this->populateDto( $nestedDto, $value );
					}
				}
				else
				{
					// Set scalar or array values directly
					$dto->$key = $value;
				}
			}
			catch( Validation $exception )
			{
				Log::warning( $exception->getMessage() );
				$this->_errors = array_merge( $this->_errors, $exception->errors );
			}
		}
	}

	/**
	 * Validate HTTP headers against required headers
	 * @return void
	 */
	protected function validateHeaders(): void
	{
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
	}
}
