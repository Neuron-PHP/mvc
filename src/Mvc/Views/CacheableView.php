<?php
namespace Neuron\Mvc\Views;

use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Patterns\Registry;

trait CacheableView
{
	private ?ViewCache $_Cache = null;

	/**
	 * Get cache key for current view
	 *
	 * @param array $Data
	 * @return string
	 */
	protected function getCacheKey( array $Data ): string
	{
		$Cache = $this->getCache();
		
		if( !$Cache )
		{
			return '';
		}
		
		return $Cache->generateKey( 
			$this->getController(), 
			$this->getPage(), 
			$Data 
		);
	}

	/**
	 * Get cached content
	 *
	 * @param string $Key
	 * @return string|null
	 */
	protected function getCachedContent( string $Key ): ?string
	{
		$Cache = $this->getCache();
		
		if( !$Cache || !$Cache->isEnabled() )
		{
			return null;
		}
		
		return $Cache->get( $Key );
	}

	/**
	 * Set cached content
	 *
	 * @param string $Key
	 * @param string $Content
	 * @return void
	 */
	protected function setCachedContent( string $Key, string $Content ): void
	{
		$Cache = $this->getCache();
		
		if( !$Cache || !$Cache->isEnabled() )
		{
			return;
		}
		
		try
		{
			$Cache->set( $Key, $Content );
		}
		catch( CacheException $e )
		{
			// Silently fail on cache write errors
		}
	}

	/**
	 * Check if cache is enabled
	 *
	 * @return bool
	 */
	protected function isCacheEnabled(): bool
	{
		$Cache = $this->getCache();
		
		return $Cache && $Cache->isEnabled();
	}

	/**
	 * Get cache instance
	 *
	 * @return ViewCache|null
	 */
	private function getCache(): ?ViewCache
	{
		if( $this->_Cache === null )
		{
			$this->_Cache = $this->initializeCache();
		}
		
		return $this->_Cache;
	}

	/**
	 * Initialize cache from registry
	 *
	 * @return ViewCache|null
	 */
	private function initializeCache(): ?ViewCache
	{
		$Registry = Registry::getInstance();
		
		// Check if cache is already in registry
		$ViewCache = $Registry->get( 'ViewCache' );
		if( $ViewCache !== null )
		{
			return $ViewCache;
		}
		
		// Try to create cache from settings
		$Settings = $Registry->get( 'Settings' );
		if( $Settings !== null )
		{
			// Handle both SettingManager and ISettingSource
			if( $Settings instanceof \Neuron\Data\Setting\SettingManager )
			{
				$SettingSource = $Settings->getSource();
			}
			else
			{
				$SettingSource = $Settings;
			}
			
			$Config = CacheConfig::fromSettings( $SettingSource );
			
			if( $Config->isEnabled() )
			{
				$ViewType = strtolower( (new \ReflectionClass( $this ))->getShortName() );
				
				if( !$Config->isViewTypeEnabled( $ViewType ) )
				{
					return null;
				}
				
				try
				{
					$BasePath = $Registry->get( 'Base.Path' ) ?? '.';
					$CachePath = $BasePath . DIRECTORY_SEPARATOR . $Config->getCachePath();
					
					$Storage = new FileCacheStorage( $CachePath );
					$Cache = new ViewCache( $Storage, true, $Config->getDefaultTtl(), $Config );
					
					$Registry->set( 'ViewCache', $Cache );
					
					return $Cache;
				}
				catch( CacheException $e )
				{
					// Unable to initialize cache
					return null;
				}
			}
		}
		
		return null;
	}
}