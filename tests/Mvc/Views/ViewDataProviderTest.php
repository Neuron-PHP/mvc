<?php

namespace Mvc\Views;

use Neuron\Mvc\Views\ViewDataProvider;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class ViewDataProviderTest extends TestCase
{
	protected function setUp(): void
	{
		// Clear the singleton instance before each test
		Registry::getInstance()->reset();
		$reflection = new \ReflectionClass( ViewDataProvider::class );
		$instance = $reflection->getProperty( '_instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	public function testGetInstanceReturnsSingleton()
	{
		$provider1 = ViewDataProvider::getInstance();
		$provider2 = ViewDataProvider::getInstance();

		$this->assertSame( $provider1, $provider2 );
	}

	public function testGetInstanceRegistersInRegistry()
	{
		$provider = ViewDataProvider::getInstance();
		$fromRegistry = Registry::getInstance()->get( 'ViewDataProvider' );

		$this->assertSame( $provider, $fromRegistry );
	}

	public function testShareStoresValue()
	{
		$provider = ViewDataProvider::getInstance();
		$provider->share( 'siteName', 'Test Site' );

		$this->assertEquals( 'Test Site', $provider->get( 'siteName' ) );
	}

	public function testShareReturnsFluentInterface()
	{
		$provider = ViewDataProvider::getInstance();
		$result = $provider->share( 'key', 'value' );

		$this->assertSame( $provider, $result );
	}

	public function testShareMultipleStoresAllValues()
	{
		$provider = ViewDataProvider::getInstance();
		$provider->shareMultiple(
			[
				'one' => 1,
				'two' => 2,
				'three' => 3
			]
		);

		$this->assertEquals( 1, $provider->get( 'one' ) );
		$this->assertEquals( 2, $provider->get( 'two' ) );
		$this->assertEquals( 3, $provider->get( 'three' ) );
	}

	public function testShareMultipleReturnsFluentInterface()
	{
		$provider = ViewDataProvider::getInstance();
		$result = $provider->shareMultiple( [ 'key' => 'value' ] );

		$this->assertSame( $provider, $result );
	}

	public function testGetReturnsDefaultForMissingKey()
	{
		$provider = ViewDataProvider::getInstance();

		$this->assertEquals( 'default', $provider->get( 'nonexistent', 'default' ) );
		$this->assertNull( $provider->get( 'nonexistent' ) );
	}

	public function testHasReturnsTrueForExistingKey()
	{
		$provider = ViewDataProvider::getInstance();
		$provider->share( 'exists', 'value' );

		$this->assertTrue( $provider->has( 'exists' ) );
	}

	public function testHasReturnsFalseForMissingKey()
	{
		$provider = ViewDataProvider::getInstance();

		$this->assertFalse( $provider->has( 'nonexistent' ) );
	}

	public function testGetResolvesCallable()
	{
		$provider = ViewDataProvider::getInstance();
		$counter = 0;

		$provider->share( 'dynamic', function() use ( &$counter ) {
			return ++$counter;
		});

		// Each call should execute the callable
		$this->assertEquals( 1, $provider->get( 'dynamic' ) );
		$this->assertEquals( 2, $provider->get( 'dynamic' ) );
		$this->assertEquals( 3, $provider->get( 'dynamic' ) );
	}

	public function testAllReturnsAllDataWithResolvedCallables()
	{
		$provider = ViewDataProvider::getInstance();

		$provider->shareMultiple(
			[
				'static' => 'value',
				'dynamic' => fn() => 'computed',
				'number' => 42
			]
		);

		$all = $provider->all();

		$this->assertArrayHasKey( 'static', $all );
		$this->assertArrayHasKey( 'dynamic', $all );
		$this->assertArrayHasKey( 'number', $all );
		$this->assertEquals( 'value', $all['static'] );
		$this->assertEquals( 'computed', $all['dynamic'] );
		$this->assertEquals( 42, $all['number'] );
	}

	public function testAllResolvesCallablesEachTime()
	{
		$provider = ViewDataProvider::getInstance();
		$counter = 0;

		$provider->share( 'counter', function() use ( &$counter ) {
			return ++$counter;
		});

		$all1 = $provider->all();
		$all2 = $provider->all();

		// Callables should be executed each time all() is called
		$this->assertEquals( 1, $all1['counter'] );
		$this->assertEquals( 2, $all2['counter'] );
	}

	public function testRemoveDeletesKey()
	{
		$provider = ViewDataProvider::getInstance();
		$provider->share( 'toRemove', 'value' );

		$this->assertTrue( $provider->has( 'toRemove' ) );

		$provider->remove( 'toRemove' );

		$this->assertFalse( $provider->has( 'toRemove' ) );
	}

	public function testRemoveReturnsFluentInterface()
	{
		$provider = ViewDataProvider::getInstance();
		$provider->share( 'key', 'value' );
		$result = $provider->remove( 'key' );

		$this->assertSame( $provider, $result );
	}

	public function testClearRemovesAllData()
	{
		$provider = ViewDataProvider::getInstance();
		$provider->shareMultiple(
			[
				'one' => 1,
				'two' => 2,
				'three' => 3
			]
		);

		$this->assertEquals( 3, $provider->count() );

		$provider->clear();

		$this->assertEquals( 0, $provider->count() );
		$this->assertFalse( $provider->has( 'one' ) );
		$this->assertFalse( $provider->has( 'two' ) );
		$this->assertFalse( $provider->has( 'three' ) );
	}

	public function testClearReturnsFluentInterface()
	{
		$provider = ViewDataProvider::getInstance();
		$result = $provider->clear();

		$this->assertSame( $provider, $result );
	}

	public function testCountReturnsNumberOfItems()
	{
		$provider = ViewDataProvider::getInstance();

		$this->assertEquals( 0, $provider->count() );

		$provider->share( 'one', 1 );
		$this->assertEquals( 1, $provider->count() );

		$provider->share( 'two', 2 );
		$this->assertEquals( 2, $provider->count() );

		$provider->remove( 'one' );
		$this->assertEquals( 1, $provider->count() );
	}

	public function testFluentChaining()
	{
		$provider = ViewDataProvider::getInstance();

		$provider
			->share( 'one', 1 )
			->share( 'two', 2 )
			->shareMultiple( [ 'three' => 3, 'four' => 4 ] )
			->remove( 'four' );

		$this->assertEquals( 3, $provider->count() );
		$this->assertTrue( $provider->has( 'one' ) );
		$this->assertTrue( $provider->has( 'two' ) );
		$this->assertTrue( $provider->has( 'three' ) );
		$this->assertFalse( $provider->has( 'four' ) );
	}

	public function testShareSupportsComplexCallables()
	{
		$provider = ViewDataProvider::getInstance();

		$provider->share( 'user', function() {
			return (object) [
				'name' => 'John Doe',
				'email' => 'john@example.com'
			];
		});

		$user = $provider->get( 'user' );

		$this->assertIsObject( $user );
		$this->assertEquals( 'John Doe', $user->name );
		$this->assertEquals( 'john@example.com', $user->email );
	}
}
