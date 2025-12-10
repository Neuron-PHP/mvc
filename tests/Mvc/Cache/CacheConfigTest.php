<?php

namespace Tests\Mvc\Cache;

use Neuron\Data\Settings\Source\Memory;
use Neuron\Mvc\Cache\CacheConfig;
use PHPUnit\Framework\TestCase;

class CacheConfigTest extends TestCase
{
	/**
	 * Test default values
	 */
	public function testDefaultValues()
	{
		$Config = new CacheConfig( [] );
		
		$this->assertFalse( $Config->isEnabled() );
		$this->assertEquals( 'cache/views', $Config->getCachePath() );
		$this->assertEquals( 3600, $Config->getDefaultTtl() );
		$this->assertEquals( 'file', $Config->getStorageType() );
		$this->assertEquals( 0.01, $Config->getGcProbability() );
		$this->assertEquals( 100, $Config->getGcDivisor() );
	}
	
	/**
	 * Test custom values
	 */
	public function testCustomValues()
	{
		$Settings = [
			'enabled' => true,
			'path' => '/custom/cache/path',
			'ttl' => 7200,
			'storage' => 'redis',
			'gc_probability' => 0.05,
			'gc_divisor' => 50
		];
		
		$Config = new CacheConfig( $Settings );
		
		$this->assertTrue( $Config->isEnabled() );
		$this->assertEquals( '/custom/cache/path', $Config->getCachePath() );
		$this->assertEquals( 7200, $Config->getDefaultTtl() );
		$this->assertEquals( 'redis', $Config->getStorageType() );
		$this->assertEquals( 0.05, $Config->getGcProbability() );
		$this->assertEquals( 50, $Config->getGcDivisor() );
	}
	
	/**
	 * Test view type specific settings
	 */
	public function testViewTypeSettings()
	{
		$Settings = [
			'enabled' => true,
			'html' => true,
			'json' => false,
			'xml' => true,
			'markdown' => false
		];
		
		$Config = new CacheConfig( $Settings );
		
		// Test configured view types
		$this->assertTrue( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertFalse( $Config->isViewTypeEnabled( 'json' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'xml' ) );
		$this->assertFalse( $Config->isViewTypeEnabled( 'markdown' ) );
		
		// Test default for unconfigured view type
		$this->assertTrue( $Config->isViewTypeEnabled( 'custom' ) );
	}
	
	/**
	 * Test view type defaults when no view settings provided
	 */
	public function testViewTypeDefaults()
	{
		$Config = new CacheConfig( [ 'enabled' => true ] );
		
		// All view types should default to true
		$this->assertTrue( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'json' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'xml' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'markdown' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'any' ) );
	}
	
	/**
	 * Test fromSettings factory method
	 */
	public function testFromSettings()
	{
		$Settings = new Memory();
		$Settings->set( 'cache', 'enabled', 'true' );
		$Settings->set( 'cache', 'path', '/app/cache' );
		$Settings->set( 'cache', 'ttl', '1800' );
		$Settings->set( 'cache', 'storage', 'memcached' );
		$Settings->set( 'cache', 'html', 'false' );
		$Settings->set( 'cache', 'json', 'true' );
		$Settings->set( 'cache', 'xml', 'false' );
		$Settings->set( 'cache', 'markdown', 'true' );
		$Settings->set( 'cache', 'gc_probability', '0.02' );
		$Settings->set( 'cache', 'gc_divisor', '200' );
		
		$Config = CacheConfig::fromSettings( $Settings );
		
		$this->assertTrue( $Config->isEnabled() );
		$this->assertEquals( '/app/cache', $Config->getCachePath() );
		$this->assertEquals( 1800, $Config->getDefaultTtl() );
		$this->assertEquals( 'memcached', $Config->getStorageType() );
		$this->assertFalse( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'json' ) );
		$this->assertFalse( $Config->isViewTypeEnabled( 'xml' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'markdown' ) );
		$this->assertEquals( 0.02, $Config->getGcProbability() );
		$this->assertEquals( 200, $Config->getGcDivisor() );
	}
	
	/**
	 * Test fromSettings with boolean variations
	 */
	public function testFromSettingsBooleanVariations()
	{
		// Test with '1' and '0'
		$Settings = new Memory();
		$Settings->set( 'cache', 'enabled', '1' );
		$Settings->set( 'cache', 'html', '0' );
		$Settings->set( 'cache', 'json', '1' );
		
		$Config = CacheConfig::fromSettings( $Settings );
		
		$this->assertTrue( $Config->isEnabled() );
		$this->assertFalse( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'json' ) );
		
		// Test with 'false'
		$Settings2 = new Memory();
		$Settings2->set( 'cache', 'enabled', 'false' );
		$Settings2->set( 'cache', 'html', 'false' );
		
		$Config2 = CacheConfig::fromSettings( $Settings2 );
		
		$this->assertFalse( $Config2->isEnabled() );
		$this->assertFalse( $Config2->isViewTypeEnabled( 'html' ) );
	}
	
	/**
	 * Test partial configuration
	 */
	public function testPartialConfiguration()
	{
		$Settings = [
			'enabled' => true,
			'html' => false
		];
		
		$Config = new CacheConfig( $Settings );
		
		$this->assertFalse( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'json' ) ); // Default
		$this->assertTrue( $Config->isViewTypeEnabled( 'xml' ) );  // Default
		$this->assertTrue( $Config->isViewTypeEnabled( 'markdown' ) ); // Default
	}
	
	/**
	 * Test cache disabled globally but enabled for specific view type
	 */
	public function testGloballyDisabledButViewEnabled()
	{
		$Settings = [
			'enabled' => false,
			'html' => true,
			'json' => true
		];

		$Config = new CacheConfig( $Settings );

		// Global cache is disabled
		$this->assertFalse( $Config->isEnabled() );

		// But view types can still have their settings
		$this->assertTrue( $Config->isViewTypeEnabled( 'html' ) );
		$this->assertTrue( $Config->isViewTypeEnabled( 'json' ) );
	}

	/**
	 * Test Redis configuration defaults
	 */
	public function testRedisConfigDefaults()
	{
		$Config = new CacheConfig( [] );

		$this->assertEquals( '127.0.0.1', $Config->getRedisHost() );
		$this->assertEquals( 6379, $Config->getRedisPort() );
		$this->assertEquals( 0, $Config->getRedisDatabase() );
		$this->assertEquals( 'neuron_cache_', $Config->getRedisPrefix() );
		$this->assertEquals( 2.0, $Config->getRedisTimeout() );
		$this->assertNull( $Config->getRedisAuth() );
		$this->assertFalse( $Config->getRedisPersistent() );
	}

	/**
	 * Test Redis configuration with custom values
	 */
	public function testRedisConfigCustomValues()
	{
		$Settings = [
			'redis_host' => '192.168.1.100',
			'redis_port' => 6380,
			'redis_database' => 2,
			'redis_prefix' => 'myapp_',
			'redis_timeout' => 5.0,
			'redis_auth' => 'mypassword',
			'redis_persistent' => true
		];

		$Config = new CacheConfig( $Settings );

		$this->assertEquals( '192.168.1.100', $Config->getRedisHost() );
		$this->assertEquals( 6380, $Config->getRedisPort() );
		$this->assertEquals( 2, $Config->getRedisDatabase() );
		$this->assertEquals( 'myapp_', $Config->getRedisPrefix() );
		$this->assertEquals( 5.0, $Config->getRedisTimeout() );
		$this->assertEquals( 'mypassword', $Config->getRedisAuth() );
		$this->assertTrue( $Config->getRedisPersistent() );
	}

	/**
	 * Test Redis persistent connection variations
	 */
	public function testRedisPersistentVariations()
	{
		// Test with true
		$Config1 = new CacheConfig( ['redis_persistent' => true] );
		$this->assertTrue( $Config1->getRedisPersistent() );

		// Test with 'true' string
		$Config2 = new CacheConfig( ['redis_persistent' => 'true'] );
		$this->assertTrue( $Config2->getRedisPersistent() );

		// Test with '1' string
		$Config3 = new CacheConfig( ['redis_persistent' => '1'] );
		$this->assertTrue( $Config3->getRedisPersistent() );

		// Test with false
		$Config4 = new CacheConfig( ['redis_persistent' => false] );
		$this->assertFalse( $Config4->getRedisPersistent() );

		// Test with '0' string
		$Config5 = new CacheConfig( ['redis_persistent' => '0'] );
		$this->assertFalse( $Config5->getRedisPersistent() );
	}

	/**
	 * Test getRedisConfig returns complete configuration
	 */
	public function testGetRedisConfig()
	{
		$Settings = [
			'redis_host' => 'redis.example.com',
			'redis_port' => 6379,
			'redis_database' => 1,
			'redis_prefix' => 'test_',
			'redis_timeout' => 3.0,
			'redis_auth' => 'secret',
			'redis_persistent' => true
		];

		$Config = new CacheConfig( $Settings );
		$redisConfig = $Config->getRedisConfig();

		$this->assertIsArray( $redisConfig );
		$this->assertEquals( 'redis.example.com', $redisConfig['host'] );
		$this->assertEquals( 6379, $redisConfig['port'] );
		$this->assertEquals( 1, $redisConfig['database'] );
		$this->assertEquals( 'test_', $redisConfig['prefix'] );
		$this->assertEquals( 3.0, $redisConfig['timeout'] );
		$this->assertEquals( 'secret', $redisConfig['auth'] );
		$this->assertTrue( $redisConfig['persistent'] );
	}

	/**
	 * Test fromSettings with Redis configuration
	 */
	public function testFromSettingsWithRedisConfig()
	{
		$Settings = new Memory();
		$Settings->set( 'cache', 'enabled', 'true' );
		$Settings->set( 'cache', 'storage', 'redis' );
		$Settings->set( 'cache', 'redis_host', 'redis-server' );
		$Settings->set( 'cache', 'redis_port', '6380' );
		$Settings->set( 'cache', 'redis_database', '3' );
		$Settings->set( 'cache', 'redis_prefix', 'cache_' );
		$Settings->set( 'cache', 'redis_timeout', '4.5' );
		$Settings->set( 'cache', 'redis_auth', 'password123' );
		$Settings->set( 'cache', 'redis_persistent', 'true' );

		$Config = CacheConfig::fromSettings( $Settings );

		$this->assertEquals( 'redis', $Config->getStorageType() );
		$this->assertEquals( 'redis-server', $Config->getRedisHost() );
		$this->assertEquals( 6380, $Config->getRedisPort() );
		$this->assertEquals( 3, $Config->getRedisDatabase() );
		$this->assertEquals( 'cache_', $Config->getRedisPrefix() );
		$this->assertEquals( 4.5, $Config->getRedisTimeout() );
		$this->assertEquals( 'password123', $Config->getRedisAuth() );
		$this->assertTrue( $Config->getRedisPersistent() );
	}

	/**
	 * Test type casting for numeric Redis settings
	 */
	public function testRedisNumericTypeCasting()
	{
		$Settings = [
			'redis_port' => '6380',      // String should be cast to int
			'redis_database' => '5',     // String should be cast to int
			'redis_timeout' => '3.5'     // String should be cast to float
		];

		$Config = new CacheConfig( $Settings );

		$this->assertIsInt( $Config->getRedisPort() );
		$this->assertEquals( 6380, $Config->getRedisPort() );

		$this->assertIsInt( $Config->getRedisDatabase() );
		$this->assertEquals( 5, $Config->getRedisDatabase() );

		$this->assertIsFloat( $Config->getRedisTimeout() );
		$this->assertEquals( 3.5, $Config->getRedisTimeout() );
	}

	/**
	 * Test fromSettings with minimal Redis configuration
	 */
	public function testFromSettingsMinimalRedisConfig()
	{
		$Settings = new Memory();
		$Settings->set( 'cache', 'enabled', 'true' );
		$Settings->set( 'cache', 'storage', 'redis' );
		// Don't set any Redis-specific parameters

		$Config = CacheConfig::fromSettings( $Settings );

		// Should use defaults
		$this->assertEquals( '127.0.0.1', $Config->getRedisHost() );
		$this->assertEquals( 6379, $Config->getRedisPort() );
		$this->assertEquals( 0, $Config->getRedisDatabase() );
		$this->assertEquals( 'neuron_cache_', $Config->getRedisPrefix() );
	}
}
