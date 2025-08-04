<?php
namespace Neuron\Mvc\Cache;

use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\ICacheStorage;

class ViewCache
{
	private bool $_Enabled;
	private string $_CachePath;
	private int $_DefaultTtl;
	private ICacheStorage $_Storage;

	/**
	 * ViewCache constructor
	 *
	 * @param ICacheStorage $Storage
	 * @param bool $Enabled
	 * @param int $DefaultTtl
	 */
	public function __construct( ICacheStorage $Storage, bool $Enabled = true, int $DefaultTtl = 3600 )
	{
		$this->_Storage = $Storage;
		$this->_Enabled = $Enabled;
		$this->_DefaultTtl = $DefaultTtl;
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

		return $this->_Storage->read( $Key );
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
		
		return $this->_Storage->write( $Key, $Content, $Ttl );
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
}