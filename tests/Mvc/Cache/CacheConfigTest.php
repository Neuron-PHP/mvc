<?php

namespace Tests\Mvc\Cache;

use Neuron\Data\Setting\Source\Memory;
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
}
