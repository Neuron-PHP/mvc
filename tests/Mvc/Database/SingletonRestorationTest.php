<?php

namespace Mvc\Database;

use PHPUnit\Framework\TestCase;
use Phinx\Db\Adapter\AdapterFactory;
use Mvc\Database\DataImporterUnitTest;

/**
 * Test to verify AdapterFactory singleton restoration between tests
 */
class SingletonRestorationTest extends TestCase
{
	/**
	 * Test that AdapterFactory singleton state is preserved
	 */
	public function testAdapterFactoryStatePreserved(): void
	{
		// Get initial state
		$factoryClass = new \ReflectionClass( AdapterFactory::class );
		$instanceProperty = $factoryClass->getProperty( 'instance' );
		$instanceProperty->setAccessible( true );
		$initialInstance = $instanceProperty->getValue();

		// Run the DataImporter tests which modify the singleton
		$testCase = new DataImporterUnitTest();
		$testCase->setUp();

		// Mock the factory in the same way the tests do
		$mockFactory = $this->createMock( AdapterFactory::class );
		$instanceProperty->setValue( null, $mockFactory );

		// Verify it was changed
		$modifiedInstance = $instanceProperty->getValue();
		$this->assertNotSame( $initialInstance, $modifiedInstance );

		// Now tear down should restore it
		$testCase->tearDown();

		// Verify it's restored
		$restoredInstance = $instanceProperty->getValue();
		$this->assertSame( $initialInstance, $restoredInstance );
	}
}