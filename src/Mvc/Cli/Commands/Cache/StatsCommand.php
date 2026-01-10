<?php

namespace Neuron\Mvc\Cli\Commands\Cache;

use Neuron\Cli\Commands\Command;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Data\Settings\Source\Yaml;

/**
 * CLI command for displaying MVC cache statistics.
 */
class StatsCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'cache:stats';
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Display MVC view cache statistics';
	}
	
	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'config', 'c', true, 'Path to configuration directory' );
		$this->addOption( 'json', 'j', false, 'Output statistics in JSON format' );
		$this->addOption( 'detailed', 'd', false, 'Show detailed breakdown' );
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
		
		if( !$cacheConfig )
		{
			$this->output->error( 'Failed to load cache configuration' );
			return 1;
		}
		
		// Gather statistics
		$stats = $this->gatherStatistics( $cacheConfig );
		
		// Output statistics
		if( $this->input->getOption( 'json' ) )
		{
			$this->outputJson( $stats );
		}
		else
		{
			$this->outputFormatted( $stats, $cacheConfig, (bool)$this->input->getOption( 'detailed' ) );
		}
		
		return 0;
	}
	
	/**
	 * Gather cache statistics
	 * 
	 * @param CacheConfig $config
	 * @return array
	 */
	private function gatherStatistics( CacheConfig $config ): array
	{
		$stats = [
			'enabled' => $config->isEnabled(),
			'path' => $config->getCachePath(),
			'ttl' => $config->getDefaultTtl(),
			'total_entries' => 0,
			'valid_entries' => 0,
			'expired_entries' => 0,
			'total_size' => 0,
			'by_type' => [
				'html' => ['count' => 0, 'size' => 0],
				'markdown' => ['count' => 0, 'size' => 0],
				'json' => ['count' => 0, 'size' => 0],
				'xml' => ['count' => 0, 'size' => 0],
			],
			'oldest_entry' => null,
			'newest_entry' => null,
		];
		
		if( !$config->isEnabled() || !is_dir( $config->getCachePath() ) )
		{
			return $stats;
		}
		
		// Scan cache directory
		$currentTime = time();
		$oldestTime = PHP_INT_MAX;
		$newestTime = 0;
		
		// Iterate through subdirectories
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( 
				$config->getCachePath(),
				\RecursiveDirectoryIterator::SKIP_DOTS
			)
		);
		
		foreach( $iterator as $file )
		{
			if( $file->isFile() )
			{
				$filename = $file->getFilename();
				
				// Skip non-cache files
				if( !str_ends_with( $filename, '.cache' ) && !str_ends_with( $filename, '.meta' ) )
				{
					continue;
				}
				
				// Process cache files only (not meta files)
				if( str_ends_with( $filename, '.cache' ) )
				{
					$stats['total_entries']++;
					$fileSize = $file->getSize();
					$stats['total_size'] += $fileSize;
					
					// Check if expired by reading meta file
					$metaFile = str_replace( '.cache', '.meta', $file->getPathname() );
					if( file_exists( $metaFile ) )
					{
						$metadata = unserialize( file_get_contents( $metaFile ) );
						if( isset( $metadata['expires'] ) )
						{
							if( $metadata['expires'] > $currentTime )
							{
								$stats['valid_entries']++;
							}
							else
							{
								$stats['expired_entries']++;
							}
							
							// Track oldest and newest
							$createdTime = $metadata['created'] ?? $file->getMTime();
							if( $createdTime < $oldestTime )
							{
								$oldestTime = $createdTime;
								$stats['oldest_entry'] = date( 'Y-m-d H:i:s', $createdTime );
							}
							if( $createdTime > $newestTime )
							{
								$newestTime = $createdTime;
								$stats['newest_entry'] = date( 'Y-m-d H:i:s', $createdTime );
							}
						}
						
						// Determine type from metadata if available
						if( isset( $metadata['type'] ) )
						{
							$type = strtolower( $metadata['type'] );
							if( isset( $stats['by_type'][$type] ) )
							{
								$stats['by_type'][$type]['count']++;
								$stats['by_type'][$type]['size'] += $fileSize;
							}
						}
					}
					else
					{
						// No metadata, count as valid but unknown type
						$stats['valid_entries']++;
					}
				}
			}
		}
		
		return $stats;
	}
	
	/**
	 * Output statistics in formatted text
	 * 
	 * @param array $stats
	 * @param CacheConfig $config
	 * @param bool $detailed
	 * @return void
	 */
	private function outputFormatted( array $stats, CacheConfig $config, bool $detailed ): void
	{
		$this->output->title( 'MVC View Cache Statistics' );
		$this->output->write( str_repeat( '=', 50 ) );
		
		// Basic information
		$this->output->info( 'Configuration:' );
		$this->output->write( 'Cache Path: ' . $stats['path'] );
		$this->output->write( 'Cache Enabled: ' . ($stats['enabled'] ? 'Yes' : 'No') );
		$this->output->write( 'Default TTL: ' . $stats['ttl'] . ' seconds (' . $this->formatTime( $stats['ttl'] ) . ')' );
		$this->output->write( '' );
		
		// Overall statistics
		$this->output->info( 'Overall Statistics:' );
		$this->output->write( 'Total Cache Entries: ' . $stats['total_entries'] );
		$this->output->write( 'Valid Entries: ' . $stats['valid_entries'] );
		$this->output->write( 'Expired Entries: ' . $stats['expired_entries'] );
		$this->output->write( 'Total Cache Size: ' . $this->formatBytes( $stats['total_size'] ) );
		
		if( $stats['total_entries'] > 0 )
		{
			$avgSize = $stats['total_size'] / $stats['total_entries'];
			$this->output->write( 'Average Entry Size: ' . $this->formatBytes( $avgSize ) );
		}
		
		if( $stats['oldest_entry'] )
		{
			$this->output->write( 'Oldest Entry: ' . $stats['oldest_entry'] );
		}
		if( $stats['newest_entry'] )
		{
			$this->output->write( 'Newest Entry: ' . $stats['newest_entry'] );
		}
		$this->output->write( '' );
		
		// View type breakdown
		if( $detailed )
		{
			$this->output->info( 'View Type Breakdown:' );
			$this->output->write( str_repeat( '-', 30 ) );
			
			foreach( $stats['by_type'] as $type => $typeStats )
			{
				if( $typeStats['count'] > 0 )
				{
					$percentage = ($typeStats['count'] / $stats['total_entries']) * 100;
					$this->output->write( sprintf( 
						'%s Views:', 
						strtoupper( $type ) 
					));
					$this->output->write( sprintf( 
						'  - Entries: %d (%.1f%%)', 
						$typeStats['count'], 
						$percentage 
					));
					$this->output->write( sprintf( 
						'  - Size: %s', 
						$this->formatBytes( $typeStats['size'] ) 
					));
					
					// Check if this type is enabled
					$methodName = 'is' . ucfirst( $type ) . 'Enabled';
					if( method_exists( $config, $methodName ) )
					{
						$isEnabled = $config->$methodName();
						$this->output->write( '  - Caching: ' . ($isEnabled ? 'Enabled' : 'Disabled') );
					}
					
					$this->output->write( '' );
				}
			}
		}
		
		// Recommendations
		if( $stats['expired_entries'] > 0 )
		{
			$this->output->info( 'Recommendations:' );
			$expiredSize = ($stats['expired_entries'] / $stats['total_entries']) * $stats['total_size'];
			$this->output->write( sprintf( 
				'- %d expired entries can be cleared (saving ~%s)', 
				$stats['expired_entries'],
				$this->formatBytes( $expiredSize )
			));
			$this->output->write( '  Run: neuron mvc:cache:clear --expired' );
		}
		
		if( !$stats['enabled'] )
		{
			$this->output->warning( 'Cache is currently disabled!' );
		}
	}
	
	/**
	 * Output statistics in JSON format
	 * 
	 * @param array $stats
	 * @return void
	 */
	private function outputJson( array $stats ): void
	{
		$this->output->write( json_encode( $stats, JSON_PRETTY_PRINT ) );
	}
	
	/**
	 * Format bytes to human readable
	 * 
	 * @param int $bytes
	 * @return string
	 */
	private function formatBytes( int $bytes ): string
	{
		$units = ['B', 'KB', 'MB', 'GB'];
		$i = 0;
		
		while( $bytes >= 1024 && $i < count( $units ) - 1 )
		{
			$bytes /= 1024;
			$i++;
		}
		
		return round( $bytes, 2 ) . ' ' . $units[$i];
	}
	
	/**
	 * Format seconds to human readable time
	 * 
	 * @param int $seconds
	 * @return string
	 */
	private function formatTime( int $seconds ): string
	{
		if( $seconds < 60 )
		{
			return $seconds . ' seconds';
		}
		elseif( $seconds < 3600 )
		{
			return round( $seconds / 60, 1 ) . ' minutes';
		}
		elseif( $seconds < 86400 )
		{
			return round( $seconds / 3600, 1 ) . ' hours';
		}
		else
		{
			return round( $seconds / 86400, 1 ) . ' days';
		}
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
