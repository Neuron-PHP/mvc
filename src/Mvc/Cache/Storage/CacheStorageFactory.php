<?php
namespace Neuron\Mvc\Cache\Storage;

use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Exceptions\CacheException;

/**
 * Factory class for creating cache storage instances based on configuration
 */
class CacheStorageFactory
{
	/**
	 * Create a cache storage instance based on configuration
	 *
	 * @param array $Config Cache configuration with keys:
	 *                      - storage: Type of storage ('file' or 'redis')
	 *                      - path: Path for file storage (required for 'file')
	 *                      - redis: Redis configuration array (required for 'redis')
	 * @return ICacheStorage
	 * @throws CacheException
	 */
	public static function create( array $Config ): ICacheStorage
	{
		$StorageType = $Config['storage'] ?? 'file';

		switch( $StorageType )
		{
			case 'file':
				return self::createFileStorage( $Config );

			case 'redis':
				return self::createRedisStorage( $Config );

			default:
				throw new CacheException( "Unknown cache storage type: $StorageType" );
		}
	}

	/**
	 * Create file-based cache storage
	 *
	 * @param array $Config
	 * @return FileCacheStorage
	 * @throws CacheException
	 */
	private static function createFileStorage( array $Config ): FileCacheStorage
	{
		if( !isset( $Config['path'] ) )
		{
			throw CacheException::storageNotConfigured();
		}

		return new FileCacheStorage( $Config['path'] );
	}

	/**
	 * Create Redis-based cache storage
	 *
	 * @param array $Config
	 * @return RedisCacheStorage
	 * @throws CacheException
	 */
	private static function createRedisStorage( array $Config ): RedisCacheStorage
	{
		// Build Redis configuration from flat structure
		$RedisConfig = [
			'host' => $Config['redis_host'] ?? '127.0.0.1',
			'port' => (int) ( $Config['redis_port'] ?? 6379 ),
			'database' => (int) ( $Config['redis_database'] ?? 0 ),
			'prefix' => $Config['redis_prefix'] ?? 'neuron_cache_',
			'timeout' => (float) ( $Config['redis_timeout'] ?? 2.0 ),
			'auth' => $Config['redis_auth'] ?? null,
			'persistent' => $Config['redis_persistent'] ?? false
		];

		// Convert string booleans to actual booleans for persistent
		if( is_string( $RedisConfig['persistent'] ) )
		{
			$RedisConfig['persistent'] = $RedisConfig['persistent'] === 'true' || $RedisConfig['persistent'] === '1';
		}

		return new RedisCacheStorage( $RedisConfig );
	}

	/**
	 * Detect and create the best available cache storage
	 *
	 * @param array $Config Optional configuration to merge with defaults
	 * @return ICacheStorage
	 * @throws CacheException
	 */
	public static function createAutoDetect( array $Config = [] ): ICacheStorage
	{
		// Try Redis first if extension is available
		if( extension_loaded( 'redis' ) )
		{
			try
			{
				$RedisConfig = array_merge( [
					'host' => '127.0.0.1',
					'port' => 6379,
					'database' => 0,
					'prefix' => 'neuron_cache_',
					'timeout' => 2.0
				], $Config['redis'] ?? [] );

				$Storage = new RedisCacheStorage( $RedisConfig );

				// Test connection
				if( $Storage->isConnected() )
				{
					return $Storage;
				}
			}
			catch( CacheException $e )
			{
				// Redis not available, fall back to file storage
			}
		}

		// Fall back to file storage
		$Path = $Config['path'] ?? sys_get_temp_dir() . '/neuron_cache';
		return new FileCacheStorage( $Path );
	}

	/**
	 * Check if a specific storage type is available
	 *
	 * @param string $Type Storage type ('file' or 'redis')
	 * @return bool
	 */
	public static function isAvailable( string $Type ): bool
	{
		switch( $Type )
		{
			case 'file':
				return true; // File storage is always available

			case 'redis':
				if( !extension_loaded( 'redis' ) )
				{
					return false;
				}

				// Try to connect to default Redis
				try
				{
					$Storage = new RedisCacheStorage();
					$Available = $Storage->isConnected();
					$Storage->disconnect();
					return $Available;
				}
				catch( CacheException $e )
				{
					return false;
				}

			default:
				return false;
		}
	}

	/**
	 * Create storage from CacheConfig object
	 *
	 * @param CacheConfig $Config
	 * @param string $BasePath Base path for file storage
	 * @return ICacheStorage
	 * @throws CacheException
	 */
	public static function createFromConfig( CacheConfig $Config, string $BasePath ): ICacheStorage
	{
		$StorageType = $Config->getStorageType();

		if( $StorageType === 'redis' )
		{
			return new RedisCacheStorage( $Config->getRedisConfig() );
		}

		// Default to file storage
		$CachePath = $BasePath . DIRECTORY_SEPARATOR . $Config->getCachePath();
		return new FileCacheStorage( $CachePath );
	}

	/**
	 * Get information about available storage types
	 *
	 * @return array
	 */
	public static function getAvailableStorageTypes(): array
	{
		$Types = [];

		// File storage is always available
		$Types['file'] = [
			'available' => true,
			'name' => 'File Storage',
			'description' => 'File-based cache storage using local filesystem'
		];

		// Check Redis availability
		$Types['redis'] = [
			'available' => self::isAvailable( 'redis' ),
			'name' => 'Redis Storage',
			'description' => 'Redis-based cache storage for high performance'
		];

		return $Types;
	}
}