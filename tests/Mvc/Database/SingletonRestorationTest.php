<?php

namespace Tests\Mvc\Database;

use PHPUnit\Framework\TestCase;
use Phinx\Db\Adapter\AdapterFactory;
use Tests\Mvc\Database\DataImporterUnitTest;

/**
 * Test to verify AdapterFactory singleton restoration between tests
 */
class SingletonRestorationTest extends TestCase
{
	/**
	 * Test that AdapterFactory singleton state is properly restored by tearDown
	 */
	public function testAdapterFactoryStateProperlyRestored(): void
	{
		// Get initial state before any test modifications
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$initialInstance = $instanceProperty->getValue();

		// Create a DataImporterUnitTest instance
		$testCase = new DataImporterUnitTest();

		// Call setUp which will capture the current state
		$testCase->setUp();

		// Run an actual test method that modifies the singleton
		// This simulates what PHPUnit would do when running the test
		$testCase->testDifferentFormatsAccepted();

		// Verify the singleton was modified by the test
		$modifiedInstance = $instanceProperty->getValue();
		$this->assertNotSame( $initialInstance, $modifiedInstance,
			'The test should have modified the AdapterFactory singleton' );

		// Now call tearDown which should restore the original state
		$testCase->tearDown();

		// Verify the singleton is restored to its original state
		$restoredInstance = $instanceProperty->getValue();
		$this->assertSame( $initialInstance, $restoredInstance,
			'tearDown should restore the AdapterFactory to its original state' );
	}

	/**
	 * Test that the restoration logic works correctly in isolation
	 */
	public function testRestorationLogicInIsolation(): void
	{
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );

		// Save current state
		$originalState = $instanceProperty->getValue();

		// Create a mock and inject it
		$mockFactory = $this->createMock( AdapterFactory::class );
		$instanceProperty->setValue( null, $mockFactory );

		// Verify it was changed
		$this->assertSame( $mockFactory, $instanceProperty->getValue() );

		// Restore the original state (simulating what tearDown does)
		$instanceProperty->setValue( null, $originalState );

		// Verify restoration
		$this->assertSame( $originalState, $instanceProperty->getValue() );
	}
}