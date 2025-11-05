<?php
namespace Mvc\Cache\Storage;

use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\RedisCacheStorage;
use PHPUnit\Framework\TestCase;

/**
 * Tests RedisCacheStorage implementation.
 * These tests will be skipped if Redis extension is not installed or Redis server is not running.
 */
class RedisCacheStorageTest extends TestCase
{
	private ?RedisCacheStorage $Storage = null;
	private bool $RedisAvailable = false;

	protected function setUp(): void
	{
		// Check if Redis extension is loaded
		if( !extension_loaded( 'redis' ) )
		{
			$this->markTestSkipped( 'Redis extension is not installed' );
			return;
		}

		// Try to connect to Redis
		try
		{
			$this->Storage = new RedisCacheStorage( [
				'host' => '127.0.0.1',
				'port' => 6379,
				'database' => 15, // Use database 15 for testing
				'prefix' => 'neuron_test_cache_'
			] );
			$this->RedisAvailable = true;
		}
		catch( CacheException $e )
		{
			$this->markTestSkipped( 'Redis server is not available: ' . $e->getMessage() );
		}
	}

	protected function tearDown(): void
	{
		if( $this->Storage && $this->RedisAvailable )
		{
			// Clean up test data
			$this->Storage->clear();
			$this->Storage->disconnect();
		}
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
		// Deleting non-existent key should return false
		$this->assertFalse( $this->Storage->delete( 'non_existent_key' ) );
	}

	public function testClear()
	{
		// Write multiple entries
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

	public function testIsExpired()
	{
		$Key = 'test_expired';
		$Content = 'Test content';

		// Write with 1 second TTL
		$this->Storage->write( $Key, $Content, 1 );
		$this->assertFalse( $this->Storage->isExpired( $Key ) );

		// Wait for expiration
		sleep( 2 );

		$this->assertTrue( $this->Storage->isExpired( $Key ) );
		$this->assertNull( $this->Storage->read( $Key ) );
	}

	public function testIsExpiredNonExistent()
	{
		$this->assertTrue( $this->Storage->isExpired( 'non_existent_key' ) );
	}

	public function testTtlHandling()
	{
		$Key = 'test_ttl';
		$Content = 'Test content';

		// Test with positive TTL
		$this->Storage->write( $Key, $Content, 10 );
		$this->assertTrue( $this->Storage->exists( $Key ) );
		$this->Storage->delete( $Key );

		// Test with zero TTL (no expiration)
		$this->Storage->write( $Key, $Content, 0 );
		$this->assertTrue( $this->Storage->exists( $Key ) );
		$this->assertFalse( $this->Storage->isExpired( $Key ) );
	}

	public function testGarbageCollection()
	{
		// Redis handles expiration automatically, so gc() should always return 0
		$Count = $this->Storage->gc();
		$this->assertEquals( 0, $Count );
	}

	public function testConnectionStatus()
	{
		$this->assertTrue( $this->Storage->isConnected() );

		$this->Storage->disconnect();
		$this->assertFalse( $this->Storage->isConnected() );
	}

	public function testReconnection()
	{
		$this->Storage->disconnect();
		$this->assertFalse( $this->Storage->isConnected() );

		$this->assertTrue( $this->Storage->reconnect() );
		$this->assertTrue( $this->Storage->isConnected() );

		// Test that cache operations work after reconnection
		$this->Storage->write( 'reconnect_test', 'test', 60 );
		$this->assertEquals( 'test', $this->Storage->read( 'reconnect_test' ) );
	}

	public function testPrefixIsolation()
	{
		// Create two storage instances with different prefixes
		$Storage1 = new RedisCacheStorage( [
			'host' => '127.0.0.1',
			'port' => 6379,
			'database' => 15,
			'prefix' => 'prefix1_'
		] );

		$Storage2 = new RedisCacheStorage( [
			'host' => '127.0.0.1',
			'port' => 6379,
			'database' => 15,
			'prefix' => 'prefix2_'
		] );

		// Write same key to both storages
		$Storage1->write( 'shared_key', 'content1', 3600 );
		$Storage2->write( 'shared_key', 'content2', 3600 );

		// Each should have its own value
		$this->assertEquals( 'content1', $Storage1->read( 'shared_key' ) );
		$this->assertEquals( 'content2', $Storage2->read( 'shared_key' ) );

		// Clear one should not affect the other
		$Storage1->clear();
		$this->assertNull( $Storage1->read( 'shared_key' ) );
		$this->assertEquals( 'content2', $Storage2->read( 'shared_key' ) );

		// Clean up
		$Storage2->clear();
		$Storage1->disconnect();
		$Storage2->disconnect();
	}

	public function testLargeContent()
	{
		$Key = 'large_content';
		// Create a 1MB string
		$Content = str_repeat( 'x', 1024 * 1024 );

		$this->assertTrue( $this->Storage->write( $Key, $Content, 60 ) );
		$Retrieved = $this->Storage->read( $Key );

		$this->assertEquals( strlen( $Content ), strlen( $Retrieved ) );
		$this->assertEquals( $Content, $Retrieved );
	}

	public function testSpecialCharactersInKey()
	{
		$Keys = [
			'key_with_spaces in it',
			'key:with:colons',
			'key/with/slashes',
			'key.with.dots',
			'key-with-dashes',
			'key_with_unicode_émoji_😀'
		];

		foreach( $Keys as $Key )
		{
			$this->Storage->write( $Key, 'test_content', 60 );
			$this->assertEquals( 'test_content', $this->Storage->read( $Key ) );
			$this->assertTrue( $this->Storage->delete( $Key ) );
		}
	}

	public function testConcurrentAccess()
	{
		$Key = 'concurrent_test';

		// Write initial value
		$this->Storage->write( $Key, 'initial', 60 );

		// Simulate concurrent updates (in real scenario these would be from different processes)
		$this->Storage->write( $Key, 'update1', 60 );
		$this->Storage->write( $Key, 'update2', 60 );
		$this->Storage->write( $Key, 'final', 60 );

		// Should get the last written value
		$this->assertEquals( 'final', $this->Storage->read( $Key ) );
	}

	public function testAuthenticationConfiguration()
	{
		// This test will fail if Redis requires authentication
		// Skip if we can connect without auth (Redis doesn't require auth)
		try
		{
			$Storage = new RedisCacheStorage( [
				'host' => '127.0.0.1',
				'port' => 6379,
				'database' => 15,
				'auth' => 'wrong_password'
			] );
			// If we get here, Redis doesn't require auth
			$this->markTestSkipped( 'Redis server does not require authentication' );
		}
		catch( CacheException $e )
		{
			// Expected behavior when auth fails
			$this->assertStringContainsString( 'auth', strtolower( $e->getMessage() ) );
		}
	}

	public function testInvalidHostConnection()
	{
		$this->expectException( CacheException::class );
		$this->expectExceptionMessage( 'Failed to connect to Redis' );

		new RedisCacheStorage( [
			'host' => 'invalid.host.that.does.not.exist',
			'port' => 6379,
			'timeout' => 1.0
		] );
	}

	public function testPersistentConnection()
	{
		$Storage1 = new RedisCacheStorage( [
			'host' => '127.0.0.1',
			'port' => 6379,
			'database' => 15,
			'prefix' => 'persist_test_',
			'persistent' => true
		] );

		$Storage1->write( 'persist_key', 'persist_value', 60 );
		$Storage1->disconnect();

		// Create new instance with same persistent ID
		$Storage2 = new RedisCacheStorage( [
			'host' => '127.0.0.1',
			'port' => 6379,
			'database' => 15,
			'prefix' => 'persist_test_',
			'persistent' => true
		] );

		// Should be able to read the value
		$this->assertEquals( 'persist_value', $Storage2->read( 'persist_key' ) );

		// Clean up
		$Storage2->clear();
		$Storage2->disconnect();
	}
}