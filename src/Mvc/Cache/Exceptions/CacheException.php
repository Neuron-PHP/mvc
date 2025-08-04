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
}