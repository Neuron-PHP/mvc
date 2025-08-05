<?php

namespace Mvc\Cache\Exceptions;

use Neuron\Mvc\Cache\Exceptions\CacheException;
use PHPUnit\Framework\TestCase;

class CacheExceptionTest extends TestCase
{
	public function testUnableToWrite()
	{
		$Path = '/tmp/test/cache.file';
		$Exception = CacheException::unableToWrite( $Path );
		
		$this->assertInstanceOf( CacheException::class, $Exception );
		$this->assertEquals( "Unable to write cache file: $Path", $Exception->getMessage() );
	}
	
	public function testInvalidKey()
	{
		$Key = 'invalid/key*with:chars';
		$Exception = CacheException::invalidKey( $Key );
		
		$this->assertInstanceOf( CacheException::class, $Exception );
		$this->assertEquals( "Invalid cache key: $Key", $Exception->getMessage() );
	}
	
	public function testStorageNotConfigured()
	{
		$Exception = CacheException::storageNotConfigured();
		
		$this->assertInstanceOf( CacheException::class, $Exception );
		$this->assertEquals( "Cache storage is not properly configured", $Exception->getMessage() );
	}
	
	public function testUnableToCreateDirectory()
	{
		$Path = '/tmp/test/cache/dir';
		$Exception = CacheException::unableToCreateDirectory( $Path );
		
		$this->assertInstanceOf( CacheException::class, $Exception );
		$this->assertEquals( "Unable to create cache directory: $Path", $Exception->getMessage() );
	}
}