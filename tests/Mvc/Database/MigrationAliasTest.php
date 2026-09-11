<?php

namespace Tests\Mvc\Database;

use Neuron\Mvc\Database\MigrationManager;
use Neuron\Mvc\Database\SchemaExporter;
use PHPUnit\Framework\TestCase;

/**
 * MVC aliases remain for one release so existing class_exists / imports keep working.
 */
class MigrationAliasTest extends TestCase
{
	public function testMigrationManagerAliasExtendsOrmClass(): void
	{
		$this->assertTrue( is_subclass_of(
			MigrationManager::class,
			\Neuron\Orm\Database\MigrationManager::class
		) );
	}

	public function testSchemaExporterAliasExtendsOrmClass(): void
	{
		$this->assertTrue( is_subclass_of(
			SchemaExporter::class,
			\Neuron\Orm\Database\SchemaExporter::class
		) );
	}
}
