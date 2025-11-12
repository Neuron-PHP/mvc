<?php
namespace Neuron\Mvc\Cache;

use Neuron\Data\Setting\Source\ISettingSource;

class CacheConfig
{
	private array $_settings;

	/**
	 * CacheConfig constructor
	 *
	 * @param array $settings
	 */
	public function __construct( array $settings )
	{
		$this->_settings = $settings;
	}

	/**
	 * Check if cache is enabled
	 *
	 * @return bool
	 */
	public function isEnabled(): bool
	{
		return $this->_settings['enabled'] ?? false;
	}

	/**
	 * Get cache path
	 *
	 * @return string
	 */
	public function getCachePath(): string
	{
		return $this->_settings['path'] ?? 'cache/views';
	}

	/**
	 * Get default TTL
	 *
	 * @return int
	 */
	public function getDefaultTtl(): int
	{
		return $this->_settings['ttl'] ?? 3600;
	}

	/**
	 * Get storage type
	 *
	 * @return string
	 */
	public function getStorageType(): string
	{
		return $this->_settings['storage'] ?? 'file';
	}

	/**
	 * Get Redis host
	 *
	 * @return string
	 */
	public function getRedisHost(): string
	{
		return $this->_settings['redis_host'] ?? '127.0.0.1';
	}

	/**
	 * Get Redis port
	 *
	 * @return int
	 */
	public function getRedisPort(): int
	{
		return (int) ($this->_settings['redis_port'] ?? 6379);
	}

	/**
	 * Get Redis database
	 *
	 * @return int
	 */
	public function getRedisDatabase(): int
	{
		return (int) ($this->_settings['redis_database'] ?? 0);
	}

	/**
	 * Get Redis prefix
	 *
	 * @return string
	 */
	public function getRedisPrefix(): string
	{
		return $this->_settings['redis_prefix'] ?? 'neuron_cache_';
	}

	/**
	 * Get Redis timeout
	 *
	 * @return float
	 */
	public function getRedisTimeout(): float
	{
		return (float) ($this->_settings['redis_timeout'] ?? 2.0);
	}

	/**
	 * Get Redis auth
	 *
	 * @return string|null
	 */
	public function getRedisAuth(): ?string
	{
		return $this->_settings['redis_auth'] ?? null;
	}

	/**
	 * Get Redis persistent connection setting
	 *
	 * @return bool
	 */
	public function getRedisPersistent(): bool
	{
		$persistent = $this->_settings['redis_persistent'] ?? false;
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
	 * @param string $viewType
	 * @return bool
	 */
	public function isViewTypeEnabled( string $viewType ): bool
	{
		return $this->_settings[$viewType] ?? true;
	}

	/**
	 * Create CacheConfig from settings source
	 *
	 * @param ISettingSource $settings
	 * @return self
	 */
	public static function fromSettings( ISettingSource $settings ): self
	{
		$cacheSettings = [];

		$enabled = $settings->get( 'cache', 'enabled' );
		if( $enabled !== null )
		{
			$cacheSettings['enabled'] = $enabled === 'true' || $enabled === '1';
		}

		$path = $settings->get( 'cache', 'path' );
		if( $path !== null )
		{
			$cacheSettings['path'] = $path;
		}

		$ttl = $settings->get( 'cache', 'ttl' );
		if( $ttl !== null )
		{
			$cacheSettings['ttl'] = (int) $ttl;
		}

		$storage = $settings->get( 'cache', 'storage' );
		if( $storage !== null )
		{
			$cacheSettings['storage'] = $storage;
		}

		// Redis configuration parameters
		$redisParams = [
			'redis_host',
			'redis_port',
			'redis_database',
			'redis_prefix',
			'redis_timeout',
			'redis_auth',
			'redis_persistent'
		];

		foreach( $redisParams as $param )
		{
			$value = $settings->get( 'cache', $param );
			if( $value !== null )
			{
				$cacheSettings[$param] = $value;
			}
		}

		// For views settings, we need to check each view type
		$viewTypes = [ 'html', 'markdown', 'json', 'xml' ];

		foreach( $viewTypes as $viewType )
		{
			$viewEnabled = $settings->get( 'cache', $viewType );
			if( $viewEnabled !== null )
			{
				$cacheSettings[$viewType] = $viewEnabled === 'true' || $viewEnabled === '1';
			}
		}

		// Get GC settings if present
		$gcProbability = $settings->get( 'cache', 'gc_probability' );
		if( $gcProbability !== null )
		{
			$cacheSettings['gc_probability'] = (float) $gcProbability;
		}

		$gcDivisor = $settings->get( 'cache', 'gc_divisor' );
		if( $gcDivisor !== null )
		{
			$cacheSettings['gc_divisor'] = (int) $gcDivisor;
		}

		return new self( $cacheSettings );
	}

	/**
	 * Get garbage collection probability
	 *
	 * @return float
	 */
	public function getGcProbability(): float
	{
		// Default: 1% chance (0.01)
		return (float) ( $this->_settings['gc_probability'] ?? 0.01 );
	}

	/**
	 * Get garbage collection divisor
	 *
	 * @return int
	 */
	public function getGcDivisor(): int
	{
		// Default divisor for probability calculation
		return (int) ( $this->_settings['gc_divisor'] ?? 100 );
	}
}
