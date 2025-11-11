<?php
namespace Neuron\Mvc\Cache;

use Exception;
use Neuron\Log\Log;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\ICacheStorage;

class ViewCache
{
	private bool $_enabled;
	private int $_defaultTtl;
	private ICacheStorage $_storage;
	private ?CacheConfig $_config;

	/**
	 * ViewCache constructor
	 *
	 * @param ICacheStorage $storage
	 * @param bool $enabled
	 * @param int $defaultTtl
	 * @param CacheConfig|null $config
	 */
	public function __construct( ICacheStorage $storage, bool $enabled = true, int $defaultTtl = 3600, ?CacheConfig $config = null )
	{
		$this->_storage = $storage;
		$this->_enabled = $enabled;
		$this->_defaultTtl = $defaultTtl;
		$this->_config = $config;
	}

	/**
	 * Get cached content
	 *
	 * @param string $key
	 * @return string|null
	 */
	public function get( string $key ): ?string
	{
		if( !$this->_enabled )
		{
			return null;
		}

		$data = $this->_storage->read( $key );

		if( !$data )
		{
			Log::debug( "Cache miss for key: $key" );
			return null;
		}

		Log::debug( "Cache hit for key: $key" );

		return $data;
	}

	/**
	 * Set cached content
	 *
	 * @param string $key
	 * @param string $content
	 * @param int|null $ttl
	 * @return bool
	 * @throws CacheException
	 */
	public function set( string $key, string $content, ?int $ttl = null ): bool
	{
		if( !$this->_enabled )
		{
			return false;
		}

		$ttl = $ttl ?? $this->_defaultTtl;

		$result = $this->_storage->write( $key, $content, $ttl );

		// Run garbage collection based on probability
		if( $result && $this->shouldRunGc() )
		{
			$this->gc();
		}

		return $result;
	}

	/**
	 * Check if cache key exists
	 *
	 * @param string $key
	 * @return bool
	 */
	public function exists( string $key ): bool
	{
		if( !$this->_enabled )
		{
			return false;
		}

		return $this->_storage->exists( $key );
	}

	/**
	 * Delete cache entry
	 *
	 * @param string $key
	 * @return bool
	 */
	public function delete( string $key ): bool
	{
		if( !$this->_enabled )
		{
			return false;
		}

		return $this->_storage->delete( $key );
	}

	/**
	 * Clear all cache
	 *
	 * @return bool
	 */
	public function clear(): bool
	{
		return $this->_storage->clear();
	}

	/**
	 * Generate cache key from controller, view and data
	 *
	 * @param string $controller
	 * @param string $view
	 * @param array $data
	 * @return string
	 */
	public function generateKey( string $controller, string $view, array $data ): string
	{
		$dataKey = $this->hashData( $data );

		return sprintf( 'view_%s_%s_%s', $controller, $view, $dataKey );
	}

	/**
	 * Check if cache is enabled
	 *
	 * @return bool
	 */
	public function isEnabled(): bool
	{
		return $this->_enabled;
	}

	/**
	 * Enable or disable cache
	 *
	 * @param bool $enabled
	 * @return void
	 */
	public function setEnabled( bool $enabled ): void
	{
		$this->_enabled = $enabled;
	}

	/**
	 * Run garbage collection to remove expired cache entries
	 *
	 * @return int Number of entries removed
	 */
	public function gc(): int
	{
		Log::debug( "Cache gc" );
		return $this->_storage->gc();
	}

	/**
	 * Hash data array for cache key
	 *
	 * @param array $data
	 * @return string
	 */
	private function hashData( array $data ): string
	{
		// Filter out non-serializable objects (like UrlHelper)
		$serializableData = $this->filterSerializableData( $data );

		ksort( $serializableData );

		return md5( serialize( $serializableData ) );
	}

	/**
	 * Filter out non-serializable data from array
	 *
	 * @param array $data
	 * @return array
	 */
	private function filterSerializableData( array $data ): array
	{
		$filtered = [];

		foreach( $data as $key => $value )
		{
			// Skip objects that are likely to contain non-serializable content
			if( is_object( $value ) )
			{
				$className = get_class( $value );
				// Skip UrlHelper and Router objects as they contain closures
				if( str_contains( $className, 'UrlHelper' ) || str_contains( $className, 'Router' ) )
				{
					continue;
				}

				// Try to serialize other objects, skip if it fails
				try
				{
					serialize( $value );
				}
				catch( Exception $e )
				{
					continue;
				}
			}

			$filtered[$key] = $value;
		}

		return $filtered;
	}

	/**
	 * Check if garbage collection should run
	 *
	 * @return bool
	 */
	private function shouldRunGc(): bool
	{
		if( !$this->_config )
		{
			// If no config, use default 1% probability
			return mt_rand( 1, 100 ) === 1;
		}

		$probability = $this->_config->getGcProbability();

		// If probability is 0, GC is disabled
		if( $probability <= 0 )
		{
			return false;
		}

		// If probability is 1 or higher, always run
		if( $probability >= 1 )
		{
			return true;
		}

		$divisor = $this->_config->getGcDivisor();

		// Roll the dice
		return mt_rand( 1, $divisor ) <= ( $probability * $divisor );
	}
}
