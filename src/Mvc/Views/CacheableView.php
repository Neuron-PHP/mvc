<?php
namespace Neuron\Mvc\Views;

use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\CacheStorageFactory;
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
		// Check view-level cache setting first
		if( $this->getCacheEnabled() === false )
		{
			return '';
		}
		
		$Cache = $this->getCache();
		
		if( !$Cache )
		{
			return '';
		}
		
		// If cache is explicitly enabled at view level, generate key even if globally disabled
		if( $this->getCacheEnabled() === true || $Cache->isEnabled() )
		{
			return $Cache->generateKey( 
				$this->getController(), 
				$this->getPage(), 
				$Data 
			);
		}
		
		return '';
	}

	/**
	 * Get cached content
	 *
	 * @param string $Key
	 * @return string|null
	 */
	protected function getCachedContent( string $Key ): ?string
	{
		// Check view-level cache setting first
		if( $this->getCacheEnabled() === false )
		{
			return null;
		}
		
		$Cache = $this->getCache();
		
		if( !$Cache )
		{
			return null;
		}
		
		// If cache is explicitly enabled at view level, bypass global check
		if( $this->getCacheEnabled() === true )
		{
			// When explicitly enabled, bypass global check
			// We temporarily enable cache to retrieve content
			$WasEnabled = $Cache->isEnabled();
			if( !$WasEnabled )
			{
				// Use reflection to temporarily enable cache
				$Reflection = new \ReflectionObject( $Cache );
				$EnabledProperty = $Reflection->getProperty( '_Enabled' );
				$EnabledProperty->setAccessible( true );
				$EnabledProperty->setValue( $Cache, true );
			}
			
			try
			{
				$Content = $Cache->get( $Key );
			}
			finally
			{
				if( !$WasEnabled )
				{
					// Restore original state
					$EnabledProperty->setValue( $Cache, false );
				}
			}
			
			return $Content;
		}
		
		// Otherwise use normal cache method which checks global setting
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
		// Check view-level cache setting first
		if( $this->getCacheEnabled() === false )
		{
			return;
		}
		
		$Cache = $this->getCache();
		
		if( !$Cache )
		{
			return;
		}
		
		// If cache is explicitly enabled at view level, use it even if globally disabled
		if( $this->getCacheEnabled() === true )
		{
			// When explicitly enabled, bypass global check
			// We can't directly access storage, so we temporarily enable cache
			$WasEnabled = $Cache->isEnabled();
			if( !$WasEnabled )
			{
				// Use reflection to temporarily enable cache
				$Reflection = new \ReflectionObject( $Cache );
				$EnabledProperty = $Reflection->getProperty( '_Enabled' );
				$EnabledProperty->setAccessible( true );
				$EnabledProperty->setValue( $Cache, true );
			}
			
			try
			{
				$Cache->set( $Key, $Content );
			}
			catch( CacheException $e )
			{
				// Silently fail on cache write errors
			}
			finally
			{
				if( !$WasEnabled )
				{
					// Restore original state
					$EnabledProperty->setValue( $Cache, false );
				}
			}
		}
		elseif( $Cache->isEnabled() )
		{
			try
			{
				$Cache->set( $Key, $Content );
			}
			catch( CacheException $e )
			{
				// Silently fail on cache write errors  
			}
		}
	}

	/**
	 * Check if cache is enabled
	 *
	 * @return bool
	 */
	protected function isCacheEnabled(): bool
	{
		// Check view-level cache setting first
		$ViewCacheSetting = $this->getCacheEnabled();
		if( $ViewCacheSetting !== null )
		{
			return $ViewCacheSetting;
		}
		
		// Fall back to global cache setting
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

					$Storage = CacheStorageFactory::createFromConfig( $Config, $BasePath );
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