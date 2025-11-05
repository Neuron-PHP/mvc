<?php
namespace Neuron\Mvc\Cache\Exceptions;

class CacheException extends \Exception
{
	/**
	 * Create exception for unable to write
	 *
	 * @param string $Path
	 * @return self
	 */
	public static function unableToWrite( string $Path ): self
	{
		return new self( "Unable to write cache file: $Path" );
	}

	/**
	 * Create exception for invalid key
	 *
	 * @param string $Key
	 * @return self
	 */
	public static function invalidKey( string $Key ): self
	{
		return new self( "Invalid cache key: $Key" );
	}

	/**
	 * Create exception for storage not configured
	 *
	 * @return self
	 */
	public static function storageNotConfigured(): self
	{
		return new self( "Cache storage is not properly configured" );
	}

	/**
	 * Create exception for unable to create directory
	 *
	 * @param string $Path
	 * @return self
	 */
	public static function unableToCreateDirectory( string $Path ): self
	{
		return new self( "Unable to create cache directory: $Path" );
	}

	/**
	 * Create exception for Redis extension not loaded
	 *
	 * @return self
	 */
	public static function redisExtensionNotLoaded(): self
	{
		return new self( "Redis extension is not loaded. Install it via PECL: pecl install redis" );
	}

	/**
	 * Create exception for Redis connection failed
	 *
	 * @param string $Host
	 * @param int $Port
	 * @param string|null $Error
	 * @return self
	 */
	public static function connectionFailed( string $Host, int $Port, ?string $Error = null ): self
	{
		$Message = "Failed to connect to Redis at $Host:$Port";
		if( $Error )
		{
			$Message .= ": $Error";
		}
		return new self( $Message );
	}

	/**
	 * Create exception for Redis authentication failed
	 *
	 * @return self
	 */
	public static function authenticationFailed(): self
	{
		return new self( "Redis authentication failed. Check your password configuration" );
	}

	/**
	 * Create exception for connection not established
	 *
	 * @return self
	 */
	public static function connectionNotEstablished(): self
	{
		return new self( "Redis connection not established" );
	}

	/**
	 * Create exception for Redis operation error
	 *
	 * @param string $Operation
	 * @param string $Error
	 * @return self
	 */
	public static function redisError( string $Operation, string $Error ): self
	{
		return new self( "Redis $Operation operation failed: $Error" );
	}

	/**
	 * Create exception for serialization failed
	 *
	 * @param string $Error
	 * @return self
	 */
	public static function serializationFailed( string $Error ): self
	{
		return new self( "Failed to serialize cache data: $Error" );
	}
}