<?php
namespace Neuron\Mvc\Cache\Storage;

use Neuron\Core\System\IClock;
use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealClock;
use Neuron\Core\System\RealFileSystem;
use Neuron\Log\Log;
use Neuron\Mvc\Cache\Exceptions\CacheException;

class FileCacheStorage implements ICacheStorage
{
	private string $_BasePath;
	private IFileSystem $fs;
	private IClock $clock;

	/**
	 * FileCacheStorage constructor
	 *
	 * @param string $BasePath
	 * @param IFileSystem|null $fs File system implementation (null = use real file system)
	 * @param IClock|null $clock Clock implementation (null = use real clock)
	 * @throws CacheException
	 */
	public function __construct( string $BasePath, ?IFileSystem $fs = null, ?IClock $clock = null )
	{
		$this->_BasePath = rtrim( $BasePath, DIRECTORY_SEPARATOR );
		$this->fs = $fs ?? new RealFileSystem();
		$this->clock = $clock ?? new RealClock();
		$this->ensureDirectoryExists( $this->_BasePath );
	}

	/**
	 * Read content from cache
	 *
	 * @param string $Key
	 * @return string|null
	 */
	public function read( string $Key ): ?string
	{
		if( $this->isExpired( $Key ) )
		{
			Log::debug( "Cache entry expired for key: $Key" );
			$this->delete( $Key );
			return null;
		}

		$FilePath = $this->getFilePath( $Key );

		if( !$this->fs->fileExists( $FilePath ) )
		{
			return null;
		}

		$Content = $this->fs->readFile( $FilePath );

		return $Content !== false ? $Content : null;
	}

	/**
	 * Write content to cache
	 *
	 * @param string $Key
	 * @param string $Content
	 * @param int $Ttl
	 * @return bool
	 * @throws CacheException
	 */
	public function write( string $Key, string $Content, int $Ttl ): bool
	{
		$FilePath = $this->getFilePath( $Key );
		$MetaPath = $this->getMetaPath( $Key );

		$this->ensureDirectoryExists( dirname( $FilePath ) );

		$FileWritten = $this->fs->writeFile( $FilePath, $Content ) !== false;

		if( !$FileWritten )
		{
			throw CacheException::unableToWrite( $FilePath );
		}

		$MetaData = [
			'created' => $this->clock->time(),
			'ttl' => $Ttl,
			'expires' => $this->clock->time() + $Ttl
		];

		$MetaWritten = $this->fs->writeFile( $MetaPath, json_encode( $MetaData ) ) !== false;

		if( !$MetaWritten )
		{
			$this->fs->unlink( $FilePath );
			throw CacheException::unableToWrite( $MetaPath );
		}

		return true;
	}

	/**
	 * Check if cache key exists
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function exists( string $Key ): bool
	{
		return $this->fs->fileExists( $this->getFilePath( $Key ) ) && !$this->isExpired( $Key );
	}

	/**
	 * Delete cache entry
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function delete( string $Key ): bool
	{
		$FilePath = $this->getFilePath( $Key );
		$MetaPath = $this->getMetaPath( $Key );

		$FileDeleted = true;
		$MetaDeleted = true;

		if( $this->fs->fileExists( $FilePath ) )
		{
			$FileDeleted = $this->fs->unlink( $FilePath );
		}

		if( $this->fs->fileExists( $MetaPath ) )
		{
			$MetaDeleted = $this->fs->unlink( $MetaPath );
		}

		return $FileDeleted && $MetaDeleted;
	}

	/**
	 * Clear all cache entries
	 *
	 * @return bool
	 */
	public function clear(): bool
	{
		if( !$this->fs->isDir( $this->_BasePath ) )
		{
			return true;
		}

		// Delete all contents but keep the base directory
		$this->recursiveDelete( $this->_BasePath, false );

		return true;
	}

	/**
	 * Check if cache entry is expired
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function isExpired( string $Key ): bool
	{
		$MetaPath = $this->getMetaPath( $Key );

		if( !$this->fs->fileExists( $MetaPath ) )
		{
			return true;
		}

		$MetaContent = $this->fs->readFile( $MetaPath );

		if( $MetaContent === false )
		{
			return true;
		}

		$MetaData = json_decode( $MetaContent, true );

		if( !$MetaData || !isset( $MetaData['expires'] ) )
		{
			return true;
		}

		return $this->clock->time() > $MetaData['expires'];
	}

	/**
	 * Run garbage collection to remove expired cache entries
	 *
	 * @return int Number of entries removed
	 */
	public function gc(): int
	{
		Log::debug( "FileCacheStorage gc" );
		$Count = 0;

		if( !$this->fs->isDir( $this->_BasePath ) )
		{
			return 0;
		}

		$this->scanAndClean( $this->_BasePath, $Count );

		return $Count;
	}

	/**
	 * Get file path for cache key
	 *
	 * @param string $Key
	 * @return string
	 */
	private function getFilePath( string $Key ): string
	{
		$Hash = md5( $Key );
		$SubDir = substr( $Hash, 0, 2 );
		
		return $this->_BasePath . DIRECTORY_SEPARATOR . $SubDir . DIRECTORY_SEPARATOR . $Hash . '.cache';
	}

	/**
	 * Get metadata file path for cache key
	 *
	 * @param string $Key
	 * @return string
	 */
	private function getMetaPath( string $Key ): string
	{
		$Hash = md5( $Key );
		$SubDir = substr( $Hash, 0, 2 );
		
		return $this->_BasePath . DIRECTORY_SEPARATOR . $SubDir . DIRECTORY_SEPARATOR . $Hash . '.meta';
	}

	/**
	 * Ensure directory exists
	 *
	 * @param string $Path
	 * @return void
	 * @throws CacheException
	 */
	private function ensureDirectoryExists( string $Path ): void
	{
		if( !$this->fs->isDir( $Path ) )
		{
			if( !$this->fs->mkdir( $Path, 0755, true ) && !$this->fs->isDir( $Path ) )
			{
				throw CacheException::unableToCreateDirectory( $Path );
			}
		}
	}

	/**
	 * Recursively delete directory contents
	 *
	 * @param string $Path
	 * @param bool $DeleteSelf
	 * @return bool
	 */
	private function recursiveDelete( string $Path, bool $DeleteSelf = true ): bool
	{
		if( !$this->fs->isDir( $Path ) )
		{
			return false;
		}

		// Use scandir for better compatibility
		$Items = $this->fs->scandir( $Path );

		if( $Items === false )
		{
			return false;
		}

		foreach( $Items as $Item )
		{
			if( $Item === '.' || $Item === '..' )
			{
				continue;
			}

			$ItemPath = $Path . DIRECTORY_SEPARATOR . $Item;

			if( $this->fs->isDir( $ItemPath ) )
			{
				$this->recursiveDelete( $ItemPath, true );
			}
			else
			{
				$this->fs->unlink( $ItemPath );
			}
		}

		if( $DeleteSelf )
		{
			$this->fs->rmdir( $Path );
		}

		return true;
	}

	/**
	 * Scan directory and clean expired entries
	 *
	 * @param string $Dir
	 * @param int $Count
	 * @return void
	 */
	private function scanAndClean( string $Dir, int &$Count ): void
	{
		$Items = $this->fs->scandir( $Dir );

		if( $Items === false )
		{
			return;
		}

		foreach( $Items as $Item )
		{
			if( $Item === '.' || $Item === '..' )
			{
				continue;
			}

			$ItemPath = $Dir . DIRECTORY_SEPARATOR . $Item;

			if( $this->fs->isDir( $ItemPath ) )
			{
				// Recursively scan subdirectories
				$this->scanAndClean( $ItemPath, $Count );
			}
			elseif( substr( $Item, -5 ) === '.meta' )
			{
				// Check if this meta file indicates an expired entry
				$MetaContent = $this->fs->readFile( $ItemPath );

				if( $MetaContent !== false )
				{
					$MetaData = json_decode( $MetaContent, true );

					if( $MetaData && isset( $MetaData['expires'] ) && $this->clock->time() > $MetaData['expires'] )
					{
						Log::debug( "Cache entry expired for key: $ItemPath" );

						// Remove the meta file
						$this->fs->unlink( $ItemPath );

						// Remove the corresponding cache file
						$CachePath = substr( $ItemPath, 0, -5 ) . '.cache';
						if( $this->fs->fileExists( $CachePath ) )
						{
							$this->fs->unlink( $CachePath );
						}

						$Count++;
					}
				}
			}
		}

		// Try to remove empty subdirectories (but not the base directory)
		if( $Dir !== $this->_BasePath )
		{
			$this->fs->rmdir( $Dir );
		}
	}
}
