<?php

namespace Neuron\Mvc\Cli\Commands\Cache;

use Neuron\Cli\Commands\Command;
use Neuron\Mvc\Cache\Storage\CacheStorageFactory;
use Neuron\Mvc\Cache\Storage\ICacheStorage;
use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Data\Settings\Source\Yaml;

/**
 * CLI command for clearing the MVC view cache.
 */
class ClearCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'cache:clear';
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Clear MVC view cache entries';
	}
	
	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'type', 't', true, 'Clear specific cache type (html, json, xml, markdown)' );
		$this->addOption( 'expired', 'e', false, 'Only clear expired entries' );
		$this->addOption( 'force', 'f', false, 'Clear without confirmation' );
		$this->addOption( 'config', 'c', true, 'Path to configuration directory' );
		$this->addOption( 'verbose', 'v', false, 'Show detailed output including stack traces on error' );
	}
	
	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		// Get configuration path
		$configPath = $this->input->getOption( 'config', $this->findConfigPath() );
		
		if( !$configPath || !is_dir( $configPath ) )
		{
			$this->output->error( 'Configuration directory not found: ' . ($configPath ?: 'none specified') );
			$this->output->info( 'Use --config to specify the configuration directory' );
			return 1;
		}
		
		// Load configuration
		$cacheConfig = $this->loadCacheConfiguration( $configPath );
		
		if( !$cacheConfig || !$cacheConfig->isEnabled() )
		{
			$this->output->warning( 'Cache is not enabled in configuration' );
			return 0;
		}
		
		// Get cache storage
		$basePath = dirname( $configPath );
		$storage = CacheStorageFactory::createFromConfig( $cacheConfig, $basePath );
		
		// Check what type of clear operation
		$onlyExpired = $this->input->hasOption( 'expired' );
		$type = $this->input->getOption( 'type' );
		
		// Get confirmation unless forced
		if( !$this->input->hasOption( 'force' ) )
		{
			$message = $onlyExpired 
				? 'Clear expired cache entries?' 
				: ($type 
					? "Clear all $type cache entries?" 
					: 'Clear all cache entries?');
			
			if( !$this->confirm( $message ) )
			{
				$this->output->info( 'Cache clear cancelled' );
				return 0;
			}
		}
		
		// Perform the clear operation
		try
		{
			$count = 0;
			
			if( $onlyExpired )
			{
				$count = $storage->gc();
				$this->output->success( "Cleared $count expired cache entries" );
			}
			elseif( $type )
			{
				// Clear by type (would need to implement filtering in storage)
				$count = $this->clearByType( $storage, $type );
				$this->output->success( "Cleared $count $type cache entries" );
			}
			else
			{
				$storage->clear();
				$this->output->success( "Cleared all cache entries" );
			}
			
			// Show cache path
			$this->output->info( 'Cache path: ' . $cacheConfig->getCachePath() );
			
			return 0;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error clearing cache: ' . $e->getMessage() );
			
			if( $this->input->hasOption( 'verbose' ) || $this->input->hasOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}
			
			return 1;
		}
	}
	
	/**
	 * Clear cache entries by type
	 * 
	 * @param ICacheStorage $storage
	 * @param string $type
	 * @return int Number of entries cleared
	 */
	private function clearByType( ICacheStorage $storage, string $type ): int
	{
		// This would need to be implemented in FileCacheStorage
		// For now, we'll clear all and mention it's not type-specific
		$this->output->warning( "Type-specific clearing not yet implemented. Clearing all entries." );
		return $storage->clear();
	}
	
	/**
	 * Load cache configuration from config file
	 * 
	 * @param string $configPath
	 * @return CacheConfig|null
	 */
	private function loadCacheConfiguration( string $configPath ): ?CacheConfig
	{
		$configFile = $configPath . '/neuron.yaml';
		
		if( !file_exists( $configFile ) )
		{
			$this->output->warning( 'Configuration file not found: ' . $configFile );
			return null;
		}
		
		try
		{
			$settings = new Yaml( $configFile );
			
			// Get cache settings
			$enabled = $settings->get( 'cache', 'enabled' ) ?? false;
			$path = $settings->get( 'cache', 'path' ) ?? 'cache/views';
			$ttl = $settings->get( 'cache', 'ttl' ) ?? 3600;
			
			// Make path absolute if relative
			if( !str_starts_with( $path, '/' ) )
			{
				$basePath = $settings->get( 'system', 'base_path' ) ?? dirname( $configPath );
				$path = $basePath . '/' . $path;
			}
			
			// Build cache settings array
			$cacheSettings = [
				'enabled' => $enabled,
				'path' => $path,
				'ttl' => $ttl,
				'views' => [
					'html' => $settings->get( 'cache.views', 'html' ) ?? true,
					'markdown' => $settings->get( 'cache.views', 'markdown' ) ?? true,
					'json' => $settings->get( 'cache.views', 'json' ) ?? false,
					'xml' => $settings->get( 'cache.views', 'xml' ) ?? false,
				]
			];
			
			// Create cache config
			$config = new CacheConfig( $cacheSettings );
			
			return $config;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error loading configuration: ' . $e->getMessage() );
			return null;
		}
	}
	
	/**
	 * Try to find the configuration directory
	 * 
	 * @return string|null
	 */
	private function findConfigPath(): ?string
	{
		// Try common locations
		$locations = [
			getcwd() . '/config',
			dirname( getcwd() ) . '/config',
			dirname( getcwd(), 2 ) . '/config',
			dirname( __DIR__, 5 ) . '/config',
			dirname( __DIR__, 6 ) . '/config',
		];
		
		foreach( $locations as $location )
		{
			if( is_dir( $location ) )
			{
				return $location;
			}
		}
		
		return null;
	}
}
