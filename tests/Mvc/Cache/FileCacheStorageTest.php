<?php
namespace Mvc\Cache;

use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

/**
 * Tests FileCacheStorage using vfsStream for isolation and speed.
 * Note: Some operations like clear() don't work with vfsStream due to its limitations.
 * See FileCacheStorageRealTest for tests using real filesystem.
 */
class FileCacheStorageTest extends TestCase
{
	private $Root;
	private FileCacheStorage $Storage;

	protected function setUp(): void
	{
		$this->Root = vfsStream::setup( 'cache' );
		$this->Storage = new FileCacheStorage( vfsStream::url( 'cache' ) );
	}

	public function testWriteAndRead()
	{
		$Key = 'test_key';
		$Content = 'Test content';
		$Ttl = 3600;
		
		$this->assertTrue( $this->Storage->write( $Key, $Content, $Ttl ) );
		$this->assertEquals( $Content, $this->Storage->read( $Key ) );
	}

	public function testReadNonExistent()
	{
		$this->assertNull( $this->Storage->read( 'non_existent_key' ) );
	}

	public function testExists()
	{
		$Key = 'test_exists';
		$Content = 'Test content';
		
		$this->assertFalse( $this->Storage->exists( $Key ) );
		
		$this->Storage->write( $Key, $Content, 3600 );
		
		$this->assertTrue( $this->Storage->exists( $Key ) );
	}

	public function testDelete()
	{
		$Key = 'test_delete';
		$Content = 'Test content';
		
		$this->Storage->write( $Key, $Content, 3600 );
		$this->assertTrue( $this->Storage->exists( $Key ) );
		
		$this->assertTrue( $this->Storage->delete( $Key ) );
		$this->assertFalse( $this->Storage->exists( $Key ) );
		$this->assertNull( $this->Storage->read( $Key ) );
	}

	public function testDeleteNonExistent()
	{
		$this->assertTrue( $this->Storage->delete( 'non_existent_key' ) );
	}

	public function testIsExpired()
	{
		$Key = 'test_expired';
		$Content = 'Test content';
		
		$this->Storage->write( $Key, $Content, 1 );
		$this->assertFalse( $this->Storage->isExpired( $Key ) );
		
		sleep( 2 );
		
		$this->assertTrue( $this->Storage->isExpired( $Key ) );
		$this->assertNull( $this->Storage->read( $Key ) );
	}

	public function testClear()
	{
		// Skip this test with vfsStream due to limitations with directory operations
		if( strpos( vfsStream::url( 'cache' ), 'vfs://' ) === 0 )
		{
			$this->markTestSkipped( 'Clear test skipped due to vfsStream limitations' );
			return;
		}
		
		$this->Storage->write( 'key1', 'content1', 3600 );
		$this->Storage->write( 'key2', 'content2', 3600 );
		$this->Storage->write( 'key3', 'content3', 3600 );
		
		$this->assertTrue( $this->Storage->exists( 'key1' ) );
		$this->assertTrue( $this->Storage->exists( 'key2' ) );
		$this->assertTrue( $this->Storage->exists( 'key3' ) );
		
		$this->assertTrue( $this->Storage->clear() );
		
		$this->assertFalse( $this->Storage->exists( 'key1' ) );
		$this->assertFalse( $this->Storage->exists( 'key2' ) );
		$this->assertFalse( $this->Storage->exists( 'key3' ) );
	}

	public function testSubdirectoryCreation()
	{
		$Keys = [];
		
		for( $i = 0; $i < 10; $i++ )
		{
			$Key = "test_key_$i";
			$Keys[] = $Key;
			$this->Storage->write( $Key, "Content $i", 3600 );
		}
		
		foreach( $Keys as $Key )
		{
			$this->assertTrue( $this->Storage->exists( $Key ) );
		}
		
		$this->assertTrue( count( $this->Root->getChildren() ) > 0 );
	}

	public function testInvalidCacheDirectory()
	{
		$this->expectException( CacheException::class );
		$this->expectExceptionMessage( 'Unable to create cache directory' );
		
		$InvalidPath = vfsStream::url( 'cache/invalid' );
		chmod( vfsStream::url( 'cache' ), 0000 );
		
		new FileCacheStorage( $InvalidPath );
	}
}