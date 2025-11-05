<?php
namespace Neuron\Mvc\Cache\Storage;

use Neuron\Log\Log;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Redis;
use RedisException;

class RedisCacheStorage implements ICacheStorage
{
	private ?Redis $_Redis = null;
	private string $_Prefix;
	private array $_Config;

	/**
	 * RedisCacheStorage constructor
	 *
	 * @param array $Config Redis configuration with keys:
	 *                      - host: Redis server hostname (default: 127.0.0.1)
	 *                      - port: Redis server port (default: 6379)
	 *                      - database: Redis database index (default: 0)
	 *                      - prefix: Key prefix for cache entries (default: 'neuron_cache_')
	 *                      - timeout: Connection timeout in seconds (default: 2.0)
	 *                      - auth: Authentication password (optional)
	 *                      - persistent: Use persistent connections (default: false)
	 * @throws CacheException
	 */
	public function __construct( array $Config = [] )
	{
		if( !extension_loaded( 'redis' ) )
		{
			throw CacheException::redisExtensionNotLoaded();
		}

		$this->_Config = array_merge( [
			'host' => '127.0.0.1',
			'port' => 6379,
			'database' => 0,
			'prefix' => 'neuron_cache_',
			'timeout' => 2.0,
			'auth' => null,
			'persistent' => false
		], $Config );

		$this->_Prefix = $this->_Config['prefix'];
		$this->connect();
	}

	/**
	 * Connect to Redis server
	 *
	 * @return void
	 * @throws CacheException
	 */
	private function connect(): void
	{
		try
		{
			$this->_Redis = new Redis();

			// Use persistent or regular connection
			if( $this->_Config['persistent'] )
			{
				$Connected = $this->_Redis->pconnect(
					$this->_Config['host'],
					$this->_Config['port'],
					$this->_Config['timeout'],
					$this->_Config['host'] . ':' . $this->_Config['port'] // Persistent ID
				);
			}
			else
			{
				$Connected = $this->_Redis->connect(
					$this->_Config['host'],
					$this->_Config['port'],
					$this->_Config['timeout']
				);
			}

			if( !$Connected )
			{
				throw CacheException::connectionFailed(
					$this->_Config['host'],
					$this->_Config['port']
				);
			}

			// Authenticate if password is provided
			if( $this->_Config['auth'] !== null )
			{
				if( !$this->_Redis->auth( $this->_Config['auth'] ) )
				{
					throw CacheException::authenticationFailed();
				}
			}

			// Select database
			if( $this->_Config['database'] !== 0 )
			{
				$this->_Redis->select( $this->_Config['database'] );
			}

			// Set serialization options for complex data
			$this->_Redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
		}
		catch( RedisException $e )
		{
			throw CacheException::connectionFailed(
				$this->_Config['host'],
				$this->_Config['port'],
				$e->getMessage()
			);
		}
	}

	/**
	 * Read content from cache
	 *
	 * @param string $Key
	 * @return string|null
	 */
	public function read( string $Key ): ?string
	{
		if( !$this->_Redis )
		{
			return null;
		}

		try
		{
			$PrefixedKey = $this->_Prefix . $Key;
			$Content = $this->_Redis->get( $PrefixedKey );

			if( $Content === false )
			{
				Log::debug( "Cache miss for key: $Key" );
				return null;
			}

			Log::debug( "Cache hit for key: $Key" );
			return $Content;
		}
		catch( RedisException $e )
		{
			Log::error( 'Redis read error: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Write content to cache
	 *
	 * @param string $Key
	 * @param string $Content
	 * @param int $Ttl
	 * @return bool
	 * @throws CacheException
	 */
	public function write( string $Key, string $Content, int $Ttl ): bool
	{
		if( !$this->_Redis )
		{
			throw CacheException::connectionNotEstablished();
		}

		try
		{
			$PrefixedKey = $this->_Prefix . $Key;

			// Use SETEX for atomic set with TTL
			if( $Ttl > 0 )
			{
				$Result = $this->_Redis->setex( $PrefixedKey, $Ttl, $Content );
			}
			else
			{
				// If TTL is 0 or negative, set without expiration
				$Result = $this->_Redis->set( $PrefixedKey, $Content );
			}

			if( !$Result )
			{
				throw CacheException::unableToWrite( $Key );
			}

			Log::debug( "Cache written for key: $Key with TTL: $Ttl" );
			return true;
		}
		catch( RedisException $e )
		{
			throw CacheException::redisError( 'write', $e->getMessage() );
		}
	}

	/**
	 * Check if cache key exists
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function exists( string $Key ): bool
	{
		if( !$this->_Redis )
		{
			return false;
		}

		try
		{
			$PrefixedKey = $this->_Prefix . $Key;
			return $this->_Redis->exists( $PrefixedKey ) > 0;
		}
		catch( RedisException $e )
		{
			Log::error( 'Redis exists error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Delete cache entry
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function delete( string $Key ): bool
	{
		if( !$this->_Redis )
		{
			return false;
		}

		try
		{
			$PrefixedKey = $this->_Prefix . $Key;
			$Deleted = $this->_Redis->del( $PrefixedKey );

			Log::debug( "Cache deleted for key: $Key" );
			return $Deleted > 0;
		}
		catch( RedisException $e )
		{
			Log::error( 'Redis delete error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Clear all cache entries
	 *
	 * @return bool
	 */
	public function clear(): bool
	{
		if( !$this->_Redis )
		{
			return false;
		}

		try
		{
			// Use SCAN to find all keys with our prefix
			$Iterator = null;
			$DeletedCount = 0;

			do
			{
				$Keys = $this->_Redis->scan( $Iterator, $this->_Prefix . '*', 100 );

				if( $Keys !== false && !empty( $Keys ) )
				{
					$DeletedCount += $this->_Redis->del( ...$Keys );
				}
			}
			while( $Iterator > 0 );

			Log::info( "Cache cleared, deleted $DeletedCount entries" );
			return true;
		}
		catch( RedisException $e )
		{
			Log::error( 'Redis clear error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Check if cache entry is expired
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function isExpired( string $Key ): bool
	{
		if( !$this->_Redis )
		{
			return true;
		}

		try
		{
			$PrefixedKey = $this->_Prefix . $Key;

			// Check if key exists (Redis automatically handles TTL expiration)
			// If key doesn't exist, it's either expired or never existed
			if( !$this->_Redis->exists( $PrefixedKey ) )
			{
				return true;
			}

			// Get remaining TTL
			$Ttl = $this->_Redis->ttl( $PrefixedKey );

			// TTL of -2 means key doesn't exist
			// TTL of -1 means key exists but has no expiration
			// TTL > 0 means key exists and will expire in TTL seconds
			return $Ttl === -2;
		}
		catch( RedisException $e )
		{
			Log::error( 'Redis isExpired error: ' . $e->getMessage() );
			return true;
		}
	}

	/**
	 * Run garbage collection to remove expired cache entries
	 *
	 * @return int Number of entries removed
	 */
	public function gc(): int
	{
		// Redis handles expiration automatically via TTL
		// This method exists for interface compliance
		// We can optionally force expiration of volatile keys
		Log::debug( 'RedisCacheStorage gc called - Redis handles expiration automatically' );

		if( !$this->_Redis )
		{
			return 0;
		}

		try
		{
			// Optionally, we can get info about expired keys
			$Info = $this->_Redis->info( 'stats' );
			$ExpiredKeys = $Info['expired_keys'] ?? 0;

			Log::debug( "Redis has expired $ExpiredKeys keys since startup" );
			return 0; // We don't manually expire keys
		}
		catch( RedisException $e )
		{
			Log::error( 'Redis gc error: ' . $e->getMessage() );
			return 0;
		}
	}

	/**
	 * Get Redis connection status
	 *
	 * @return bool
	 */
	public function isConnected(): bool
	{
		if( !$this->_Redis )
		{
			return false;
		}

		try
		{
			return $this->_Redis->ping() !== false;
		}
		catch( RedisException $e )
		{
			return false;
		}
	}

	/**
	 * Reconnect to Redis if connection was lost
	 *
	 * @return bool
	 */
	public function reconnect(): bool
	{
		try
		{
			$this->disconnect();
			$this->connect();
			return true;
		}
		catch( CacheException $e )
		{
			Log::error( 'Redis reconnection failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Disconnect from Redis
	 *
	 * @return void
	 */
	public function disconnect(): void
	{
		if( $this->_Redis )
		{
			try
			{
				$this->_Redis->close();
			}
			catch( RedisException $e )
			{
				// Ignore close errors
			}
			$this->_Redis = null;
		}
	}

	/**
	 * Destructor - ensure connection is closed
	 */
	public function __destruct()
	{
		$this->disconnect();
	}
}