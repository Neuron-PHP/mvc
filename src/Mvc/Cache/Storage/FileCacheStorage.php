<?php
namespace Neuron\Mvc\Cache\Storage;

use Neuron\Mvc\Cache\Exceptions\CacheException;

class FileCacheStorage implements ICacheStorage
{
	private string $_BasePath;

	/**
	 * FileCacheStorage constructor
	 *
	 * @param string $BasePath
	 * @throws CacheException
	 */
	public function __construct( string $BasePath )
	{
		$this->_BasePath = rtrim( $BasePath, DIRECTORY_SEPARATOR );
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
			$this->delete( $Key );
			return null;
		}

		$FilePath = $this->getFilePath( $Key );
		
		if( !file_exists( $FilePath ) )
		{
			return null;
		}

		$Content = file_get_contents( $FilePath );
		
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

		$FileWritten = file_put_contents( $FilePath, $Content ) !== false;
		
		if( !$FileWritten )
		{
			throw CacheException::unableToWrite( $FilePath );
		}

		$MetaData = [
			'created' => time(),
			'ttl' => $Ttl,
			'expires' => time() + $Ttl
		];

		$MetaWritten = file_put_contents( $MetaPath, json_encode( $MetaData ) ) !== false;
		
		if( !$MetaWritten )
		{
			unlink( $FilePath );
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
		return file_exists( $this->getFilePath( $Key ) ) && !$this->isExpired( $Key );
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

		if( file_exists( $FilePath ) )
		{
			$FileDeleted = unlink( $FilePath );
		}

		if( file_exists( $MetaPath ) )
		{
			$MetaDeleted = unlink( $MetaPath );
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
		if( !is_dir( $this->_BasePath ) )
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
		
		if( !file_exists( $MetaPath ) )
		{
			return true;
		}

		$MetaContent = file_get_contents( $MetaPath );
		
		if( $MetaContent === false )
		{
			return true;
		}

		$MetaData = json_decode( $MetaContent, true );
		
		if( !$MetaData || !isset( $MetaData['expires'] ) )
		{
			return true;
		}

		return time() > $MetaData['expires'];
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
		if( !is_dir( $Path ) )
		{
			if( !mkdir( $Path, 0755, true ) && !is_dir( $Path ) )
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
		if( !is_dir( $Path ) )
		{
			return false;
		}

		// Use scandir for better compatibility with vfsStream
		$Items = scandir( $Path );
		
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
			
			if( is_dir( $ItemPath ) )
			{
				$this->recursiveDelete( $ItemPath, true );
			}
			else
			{
				@unlink( $ItemPath );
			}
		}
		
		if( $DeleteSelf )
		{
			@rmdir( $Path );
		}

		return true;
	}
}
