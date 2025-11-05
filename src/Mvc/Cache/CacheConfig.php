<?php
namespace Neuron\Mvc\Cache;

use Neuron\Data\Setting\Source\ISettingSource;

class CacheConfig
{
	private array $_Settings;

	/**
	 * CacheConfig constructor
	 *
	 * @param array $Settings
	 */
	public function __construct( array $Settings )
	{
		$this->_Settings = $Settings;
	}

	/**
	 * Check if cache is enabled
	 *
	 * @return bool
	 */
	public function isEnabled(): bool
	{
		return $this->_Settings['enabled'] ?? false;
	}

	/**
	 * Get cache path
	 *
	 * @return string
	 */
	public function getCachePath(): string
	{
		return $this->_Settings['path'] ?? 'cache/views';
	}

	/**
	 * Get default TTL
	 *
	 * @return int
	 */
	public function getDefaultTtl(): int
	{
		return $this->_Settings['ttl'] ?? 3600;
	}

	/**
	 * Get storage type
	 *
	 * @return string
	 */
	public function getStorageType(): string
	{
		return $this->_Settings['storage'] ?? 'file';
	}

	/**
	 * Get Redis host
	 *
	 * @return string
	 */
	public function getRedisHost(): string
	{
		return $this->_Settings['redis_host'] ?? '127.0.0.1';
	}

	/**
	 * Get Redis port
	 *
	 * @return int
	 */
	public function getRedisPort(): int
	{
		return (int) ($this->_Settings['redis_port'] ?? 6379);
	}

	/**
	 * Get Redis database
	 *
	 * @return int
	 */
	public function getRedisDatabase(): int
	{
		return (int) ($this->_Settings['redis_database'] ?? 0);
	}

	/**
	 * Get Redis prefix
	 *
	 * @return string
	 */
	public function getRedisPrefix(): string
	{
		return $this->_Settings['redis_prefix'] ?? 'neuron_cache_';
	}

	/**
	 * Get Redis timeout
	 *
	 * @return float
	 */
	public function getRedisTimeout(): float
	{
		return (float) ($this->_Settings['redis_timeout'] ?? 2.0);
	}

	/**
	 * Get Redis auth
	 *
	 * @return string|null
	 */
	public function getRedisAuth(): ?string
	{
		return $this->_Settings['redis_auth'] ?? null;
	}

	/**
	 * Get Redis persistent connection setting
	 *
	 * @return bool
	 */
	public function getRedisPersistent(): bool
	{
		$persistent = $this->_Settings['redis_persistent'] ?? false;
		return $persistent === true || $persistent === 'true' || $persistent === '1';
	}

	/**
	 * Get all Redis configuration as array
	 *
	 * @return array
	 */
	public function getRedisConfig(): array
	{
		return [
			'host' => $this->getRedisHost(),
			'port' => $this->getRedisPort(),
			'database' => $this->getRedisDatabase(),
			'prefix' => $this->getRedisPrefix(),
			'timeout' => $this->getRedisTimeout(),
			'auth' => $this->getRedisAuth(),
			'persistent' => $this->getRedisPersistent()
		];
	}

	/**
	 * Check if specific view type caching is enabled
	 *
	 * @param string $ViewType
	 * @return bool
	 */
	public function isViewTypeEnabled( string $ViewType ): bool
	{
		return $this->_Settings[$ViewType] ?? true;
	}

	/**
	 * Create CacheConfig from settings source
	 *
	 * @param ISettingSource $Settings
	 * @return self
	 */
	public static function fromSettings( ISettingSource $Settings ): self
	{
		$CacheSettings = [];
		
		$Enabled = $Settings->get( 'cache', 'enabled' );
		if( $Enabled !== null )
		{
			$CacheSettings['enabled'] = $Enabled === 'true' || $Enabled === '1';
		}
		
		$Path = $Settings->get( 'cache', 'path' );
		if( $Path !== null )
		{
			$CacheSettings['path'] = $Path;
		}
		
		$Ttl = $Settings->get( 'cache', 'ttl' );
		if( $Ttl !== null )
		{
			$CacheSettings['ttl'] = (int) $Ttl;
		}
		
		$Storage = $Settings->get( 'cache', 'storage' );
		if( $Storage !== null )
		{
			$CacheSettings['storage'] = $Storage;
		}

		// Redis configuration parameters
		$RedisParams = [
			'redis_host',
			'redis_port',
			'redis_database',
			'redis_prefix',
			'redis_timeout',
			'redis_auth',
			'redis_persistent'
		];

		foreach( $RedisParams as $Param )
		{
			$Value = $Settings->get( 'cache', $Param );
			if( $Value !== null )
			{
				$CacheSettings[$Param] = $Value;
			}
		}

		// For views settings, we need to check each view type
		$ViewTypes = [ 'html', 'markdown', 'json', 'xml' ];
		
		foreach( $ViewTypes as $ViewType )
		{
			$ViewEnabled = $Settings->get( 'cache', $ViewType );
			if( $ViewEnabled !== null )
			{
				$CacheSettings[$ViewType] = $ViewEnabled === 'true' || $ViewEnabled === '1';
			}
		}
		
		// Get GC settings if present
		$GcProbability = $Settings->get( 'cache', 'gc_probability' );
		if( $GcProbability !== null )
		{
			$CacheSettings['gc_probability'] = (float) $GcProbability;
		}
		
		$GcDivisor = $Settings->get( 'cache', 'gc_divisor' );
		if( $GcDivisor !== null )
		{
			$CacheSettings['gc_divisor'] = (int) $GcDivisor;
		}
		
		return new self( $CacheSettings );
	}

	/**
	 * Get garbage collection probability
	 *
	 * @return float
	 */
	public function getGcProbability(): float
	{
		// Default: 1% chance (0.01)
		return (float) ( $this->_Settings['gc_probability'] ?? 0.01 );
	}

	/**
	 * Get garbage collection divisor
	 *
	 * @return int
	 */
	public function getGcDivisor(): int
	{
		// Default divisor for probability calculation
		return (int) ( $this->_Settings['gc_divisor'] ?? 100 );
	}
}
