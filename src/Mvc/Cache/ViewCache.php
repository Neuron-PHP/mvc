<?php
namespace Neuron\Mvc\Cache;

use Neuron\Log\Log;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\ICacheStorage;

class ViewCache
{
	private bool $_Enabled;
	private int $_DefaultTtl;
	private ICacheStorage $_Storage;
	private ?CacheConfig $_Config;

	/**
	 * ViewCache constructor
	 *
	 * @param ICacheStorage $Storage
	 * @param bool $Enabled
	 * @param int $DefaultTtl
	 * @param CacheConfig|null $Config
	 */
	public function __construct( ICacheStorage $Storage, bool $Enabled = true, int $DefaultTtl = 3600, ?CacheConfig $Config = null )
	{
		$this->_Storage = $Storage;
		$this->_Enabled = $Enabled;
		$this->_DefaultTtl = $DefaultTtl;
		$this->_Config = $Config;
	}

	/**
	 * Get cached content
	 *
	 * @param string $Key
	 * @return string|null
	 */
	public function get( string $Key ): ?string
	{
		if( !$this->_Enabled )
		{
			return null;
		}

		$Data = $this->_Storage->read( $Key );

		if( !$Data )
		{
			Log::debug( "Cache miss for key: $Key" );
			return null;
		}

		Log::debug( "Cache hit for key: $Key" );

		return $Data;
	}

	/**
	 * Set cached content
	 *
	 * @param string $Key
	 * @param string $Content
	 * @param int|null $Ttl
	 * @return bool
	 * @throws CacheException
	 */
	public function set( string $Key, string $Content, ?int $Ttl = null ): bool
	{
		if( !$this->_Enabled )
		{
			return false;
		}

		$Ttl = $Ttl ?? $this->_DefaultTtl;
		
		$Result = $this->_Storage->write( $Key, $Content, $Ttl );
		
		// Run garbage collection based on probability
		if( $Result && $this->shouldRunGc() )
		{
			$this->gc();
		}
		
		return $Result;
	}

	/**
	 * Check if cache key exists
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function exists( string $Key ): bool
	{
		if( !$this->_Enabled )
		{
			return false;
		}

		return $this->_Storage->exists( $Key );
	}

	/**
	 * Delete cache entry
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function delete( string $Key ): bool
	{
		if( !$this->_Enabled )
		{
			return false;
		}

		return $this->_Storage->delete( $Key );
	}

	/**
	 * Clear all cache
	 *
	 * @return bool
	 */
	public function clear(): bool
	{
		return $this->_Storage->clear();
	}

	/**
	 * Generate cache key from controller, view and data
	 *
	 * @param string $Controller
	 * @param string $View
	 * @param array $Data
	 * @return string
	 */
	public function generateKey( string $Controller, string $View, array $Data ): string
	{
		$DataKey = $this->hashData( $Data );
		
		return sprintf( 'view_%s_%s_%s', $Controller, $View, $DataKey );
	}

	/**
	 * Check if cache is enabled
	 *
	 * @return bool
	 */
	public function isEnabled(): bool
	{
		return $this->_Enabled;
	}

	/**
	 * Enable or disable cache
	 *
	 * @param bool $Enabled
	 * @return void
	 */
	public function setEnabled( bool $Enabled ): void
	{
		$this->_Enabled = $Enabled;
	}

	/**
	 * Run garbage collection to remove expired cache entries
	 *
	 * @return int Number of entries removed
	 */
	public function gc(): int
	{
		Log::debug( "Cache gc" );
		return $this->_Storage->gc();
	}

	/**
	 * Hash data array for cache key
	 *
	 * @param array $Data
	 * @return string
	 */
	private function hashData( array $Data ): string
	{
		ksort( $Data );
		
		return md5( serialize( $Data ) );
	}

	/**
	 * Check if garbage collection should run
	 *
	 * @return bool
	 */
	private function shouldRunGc(): bool
	{
		if( !$this->_Config )
		{
			// If no config, use default 1% probability
			return mt_rand( 1, 100 ) === 1;
		}
		
		$Probability = $this->_Config->getGcProbability();
		
		// If probability is 0, GC is disabled
		if( $Probability <= 0 )
		{
			return false;
		}
		
		// If probability is 1 or higher, always run
		if( $Probability >= 1 )
		{
			return true;
		}
		
		$Divisor = $this->_Config->getGcDivisor();
		
		// Roll the dice
		return mt_rand( 1, $Divisor ) <= ( $Probability * $Divisor );
	}
}
