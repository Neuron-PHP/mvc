<?php
namespace Mvc\Cache\Storage;

use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\RedisCacheStorage;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Unit tests for RedisCacheStorage using mocked Redis.
 *
 * Requirements:
 * - Redis PHP extension must be installed (but NOT a running server)
 * - Tests use mocked Redis objects to test business logic
 * - Much faster than integration tests
 * - Test error handling, key prefixing, method call sequences
 *
 * Note: If Redis extension is not installed, tests will be skipped.
 * This is a limitation of testing code that depends on PHP extensions.
 */
class RedisCacheStorageUnitTest extends TestCase
{
	protected function setUp(): void
	{
		// Skip all tests if Redis extension not loaded
		if( !extension_loaded( 'redis' ) )
		{
			$this->markTestSkipped( 'Redis extension required for unit tests (server NOT required)' );
		}
	}
	/**
	 * Helper to create RedisCacheStorage with mocked Redis.
	 * Uses reflection to bypass constructor and inject mocked Redis.
	 */
	private function createStorageWithMock( Redis $MockRedis, array $Config = [] ): RedisCacheStorage
	{
		// We need to work around the constructor creating a real Redis connection
		// Use reflection to inject our mock after construction fails
		try
		{
			$Storage = new RedisCacheStorage( $Config );
		}
		catch( CacheException $e )
		{
			// Expected - can't connect to real Redis
			// Now inject our mock using reflection
			$Reflection = new \ReflectionClass( RedisCacheStorage::class );

			// Create instance without calling constructor
			$Storage = $Reflection->newInstanceWithoutConstructor();

			// Set private properties using reflection
			$RedisProperty = $Reflection->getProperty( '_Redis' );
			$RedisProperty->setAccessible( true );
			$RedisProperty->setValue( $Storage, $MockRedis );

			$PrefixProperty = $Reflection->getProperty( '_Prefix' );
			$PrefixProperty->setAccessible( true );
			$PrefixProperty->setValue( $Storage, $Config['prefix'] ?? 'neuron_cache_' );

			$ConfigProperty = $Reflection->getProperty( '_Config' );
			$ConfigProperty->setAccessible( true );
			$ConfigProperty->setValue( $Storage, array_merge( [
				'host' => '127.0.0.1',
				'port' => 6379,
				'database' => 0,
				'prefix' => 'neuron_cache_',
				'timeout' => 2.0,
				'auth' => null,
				'persistent' => false
			], $Config ) );

			return $Storage;
		}

		// If we got here, Redis is actually running - inject mock anyway for consistency
		$Reflection = new \ReflectionClass( $Storage );
		$RedisProperty = $Reflection->getProperty( '_Redis' );
		$RedisProperty->setAccessible( true );
		$RedisProperty->setValue( $Storage, $MockRedis );

		return $Storage;
	}

	public function testReadWithPrefixedKey()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'get' )
			->with( 'test_prefix_my_key' )
			->willReturn( 'cached content' );

		$Storage = $this->createStorageWithMock( $MockRedis, [ 'prefix' => 'test_prefix_' ] );

		$Result = $Storage->read( 'my_key' );
		$this->assertEquals( 'cached content', $Result );
	}

	public function testReadReturnsNullOnCacheMiss()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'get' )
			->with( 'neuron_cache_missing_key' )
			->willReturn( false );

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->read( 'missing_key' );
		$this->assertNull( $Result );
	}

	public function testWriteWithTtlUsesSetex()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'setex' )
			->with( 'neuron_cache_test_key', 3600, 'test content' )
			->willReturn( true );

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->write( 'test_key', 'test content', 3600 );
		$this->assertTrue( $Result );
	}

	public function testWriteWithZeroTtlUsesSet()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'set' )
			->with( 'neuron_cache_test_key', 'test content' )
			->willReturn( true );

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->write( 'test_key', 'test content', 0 );
		$this->assertTrue( $Result );
	}

	public function testExistsCallsRedisExists()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'exists' )
			->with( 'neuron_cache_test_key' )
			->willReturn( 1 );

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->exists( 'test_key' );
		$this->assertTrue( $Result );
	}

	public function testExistsReturnsFalseWhenKeyDoesNotExist()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'exists' )
			->with( 'neuron_cache_missing_key' )
			->willReturn( 0 );

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->exists( 'missing_key' );
		$this->assertFalse( $Result );
	}

	public function testDeleteCallsRedisDel()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'del' )
			->with( 'neuron_cache_test_key' )
			->willReturn( 1 );

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->delete( 'test_key' );
		$this->assertTrue( $Result );
	}

	public function testDeleteReturnsFalseWhenKeyNotDeleted()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'del' )
			->with( 'neuron_cache_missing_key' )
			->willReturn( 0 );

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->delete( 'missing_key' );
		$this->assertFalse( $Result );
	}

	public function testCustomPrefixIsUsed()
	{
		$MockRedis = $this->createMock( Redis::class );
		$MockRedis->expects( $this->once() )
			->method( 'get' )
			->with( 'custom_prefix_my_key' )
			->willReturn( 'value' );

		$Storage = $this->createStorageWithMock( $MockRedis, [ 'prefix' => 'custom_prefix_' ] );

		$Result = $Storage->read( 'my_key' );
		$this->assertEquals( 'value', $Result );
	}

	public function testIsExpiredReturnsTrueWhenTtlIsNegative()
	{
		$MockRedis = $this->createMock( Redis::class );

		// First exists() is called, return false to indicate key doesn't exist
		$MockRedis->expects( $this->once() )
			->method( 'exists' )
			->with( 'neuron_cache_test_key' )
			->willReturn( 0 ); // Redis exists returns 0 for non-existent keys

		// ttl() should not be called since exists() returns false
		$MockRedis->expects( $this->never() )
			->method( 'ttl' );

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->isExpired( 'test_key' );
		$this->assertTrue( $Result );
	}

	public function testIsExpiredReturnsFalseWhenTtlIsPositive()
	{
		$MockRedis = $this->createMock( Redis::class );

		// First exists() is called, return true to indicate key exists
		$MockRedis->expects( $this->once() )
			->method( 'exists' )
			->with( 'neuron_cache_test_key' )
			->willReturn( 1 ); // Redis exists returns 1 for existing keys

		// Then ttl() is called and returns positive value
		$MockRedis->expects( $this->once() )
			->method( 'ttl' )
			->with( 'neuron_cache_test_key' )
			->willReturn( 3600 ); // Has TTL

		$Storage = $this->createStorageWithMock( $MockRedis );

		$Result = $Storage->isExpired( 'test_key' );
		$this->assertFalse( $Result );
	}

	public function testGarbageCollectionReturnsZero()
	{
		$MockRedis = $this->createMock( Redis::class );

		$Storage = $this->createStorageWithMock( $MockRedis );

		// Redis handles TTL automatically, so GC always returns 0
		$Result = $Storage->gc();
		$this->assertEquals( 0, $Result );
	}
}
