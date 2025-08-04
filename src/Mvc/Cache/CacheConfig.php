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
	 * Check if specific view type caching is enabled
	 *
	 * @param string $ViewType
	 * @return bool
	 */
	public function isViewTypeEnabled( string $ViewType ): bool
	{
		$ViewSettings = $this->_Settings['views'] ?? [];
		
		return $ViewSettings[$ViewType] ?? true;
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
		
		// For views settings, we need to check each view type
		$ViewTypes = [ 'html', 'markdown', 'json', 'xml' ];
		$ViewSettings = [];
		
		foreach( $ViewTypes as $ViewType )
		{
			$ViewEnabled = $Settings->get( 'views', $ViewType );
			if( $ViewEnabled !== null )
			{
				$ViewSettings[$ViewType] = $ViewEnabled === 'true' || $ViewEnabled === '1';
			}
		}
		
		if( !empty( $ViewSettings ) )
		{
			$CacheSettings['views'] = $ViewSettings;
		}
		
		return new self( $CacheSettings );
	}
}