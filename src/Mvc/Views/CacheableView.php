<?php
namespace Neuron\Mvc\Views;

use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\CacheStorageFactory;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Patterns\Registry;

trait CacheableView
{
	private ?ViewCache $_cache = null;

	/**
	 * Get cache key for current view
	 *
	 * @param array $data
	 * @return string
	 */
	protected function getCacheKey( array $data ): string
	{
		// Check view-level cache setting first
		if( $this->getCacheEnabled() === false )
		{
			return '';
		}

		$cache = $this->getCache();

		if( !$cache )
		{
			return '';
		}

		// If cache is explicitly enabled at view level, generate key even if globally disabled
		if( $this->getCacheEnabled() === true || $cache->isEnabled() )
		{
			return $cache->generateKey(
				$this->getController(),
				$this->getPage(),
				$data
			);
		}

		return '';
	}

	/**
	 * Get cached content
	 *
	 * @param string $key
	 * @return string|null
	 */
	protected function getCachedContent( string $key ): ?string
	{
		// Check view-level cache setting first
		if( $this->getCacheEnabled() === false )
		{
			return null;
		}

		$cache = $this->getCache();

		if( !$cache )
		{
			return null;
		}

		// If cache is explicitly enabled at view level, bypass global check
		if( $this->getCacheEnabled() === true )
		{
			// When explicitly enabled, bypass global check
			// We temporarily enable cache to retrieve content
			$wasEnabled = $cache->isEnabled();
			if( !$wasEnabled )
			{
				// Use reflection to temporarily enable cache
				$reflection = new \ReflectionObject( $cache );
				$enabledProperty = $reflection->getProperty( '_enabled' );
				$enabledProperty->setAccessible( true );
				$enabledProperty->setValue( $cache, true );
			}

			try
			{
				$content = $cache->get( $key );

				// Emit cache hit or miss event
				if( $content !== null )
				{
					\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Mvc\Events\ViewCacheHitEvent(
						$this->getController(),
						$this->getPage(),
						$key
					) );
				}
				else
				{
					\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Mvc\Events\ViewCacheMissEvent(
						$this->getController(),
						$this->getPage(),
						$key
					) );
				}
			}
			finally
			{
				if( !$wasEnabled )
				{
					// Restore original state
					$enabledProperty->setValue( $cache, false );
				}
			}

			return $content;
		}

		// Otherwise use normal cache method which checks global setting
		$content = $cache->get( $key );

		// Emit cache hit or miss event
		if( $content !== null )
		{
			\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Mvc\Events\ViewCacheHitEvent(
				$this->getController(),
				$this->getPage(),
				$key
			) );
		}
		else
		{
			\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Mvc\Events\ViewCacheMissEvent(
				$this->getController(),
				$this->getPage(),
				$key
			) );
		}

		return $content;
	}

	/**
	 * Set cached content
	 *
	 * @param string $key
	 * @param string $content
	 * @return void
	 */
	protected function setCachedContent( string $key, string $content ): void
	{
		// Check view-level cache setting first
		if( $this->getCacheEnabled() === false )
		{
			return;
		}

		$cache = $this->getCache();

		if( !$cache )
		{
			return;
		}

		// If cache is explicitly enabled at view level, use it even if globally disabled
		if( $this->getCacheEnabled() === true )
		{
			// When explicitly enabled, bypass global check
			// We can't directly access storage, so we temporarily enable cache
			$wasEnabled = $cache->isEnabled();
			if( !$wasEnabled )
			{
				// Use reflection to temporarily enable cache
				$reflection = new \ReflectionObject( $cache );
				$enabledProperty = $reflection->getProperty( '_enabled' );
				$enabledProperty->setAccessible( true );
				$enabledProperty->setValue( $cache, true );
			}

			try
			{
				$cache->set( $key, $content );
			}
			catch( CacheException $e )
			{
				// Silently fail on cache write errors
			}
			finally
			{
				if( !$wasEnabled )
				{
					// Restore original state
					$enabledProperty->setValue( $cache, false );
				}
			}
		}
		elseif( $cache->isEnabled() )
		{
			try
			{
				$cache->set( $key, $content );
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
		$viewCacheSetting = $this->getCacheEnabled();
		if( $viewCacheSetting !== null )
		{
			return $viewCacheSetting;
		}

		// Fall back to global cache setting
		$cache = $this->getCache();

		return $cache && $cache->isEnabled();
	}

	/**
	 * Get cache instance
	 *
	 * @return ViewCache|null
	 */
	private function getCache(): ?ViewCache
	{
		if( $this->_cache === null )
		{
			$this->_cache = $this->initializeCache();
		}

		return $this->_cache;
	}

	/**
	 * Initialize cache from registry
	 *
	 * @return ViewCache|null
	 */
	private function initializeCache(): ?ViewCache
	{
		$registry = Registry::getInstance();

		// Check if cache is already in registry
		$viewCache = $registry->get( 'ViewCache' );
		if( $viewCache !== null )
		{
			return $viewCache;
		}

		// Try to create cache from settings
		$settings = $registry->get( 'Settings' );
		if( $settings !== null )
		{
			// Handle both SettingManager and ISettingSource
			if( $settings instanceof \Neuron\Data\Setting\SettingManager )
			{
				$settingSource = $settings->getSource();
			}
			else
			{
				$settingSource = $settings;
			}

			$config = CacheConfig::fromSettings( $settingSource );

			if( $config->isEnabled() )
			{
				$viewType = strtolower( (new \ReflectionClass( $this ))->getShortName() );

				if( !$config->isViewTypeEnabled( $viewType ) )
				{
					return null;
				}

				try
				{
					$basePath = $registry->get( 'Base.Path' ) ?? '.';

					$storage = CacheStorageFactory::createFromConfig( $config, $basePath );
					$cache = new ViewCache( $storage, true, $config->getDefaultTtl(), $config );

					$registry->set( 'ViewCache', $cache );

					return $cache;
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