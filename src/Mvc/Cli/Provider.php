<?php

namespace Neuron\Mvc\Cli;

use Neuron\Cli\Commands\Registry;

/**
 * CLI provider for the MVC component.
 * Registers all MVC-related CLI commands.
 */
class Provider
{
	/**
	 * Register MVC commands with the CLI registry
	 *
	 * @param Registry $registry CLI Registry instance
	 * @return void
	 */
	public static function register( Registry $registry ): void
	{
		// Cache management commands
		$registry->register(
			'cache:clear',
			'Neuron\\Mvc\\Cli\\Commands\\Cache\\ClearCommand'
		);

		$registry->register(
			'cache:stats',
			'Neuron\\Mvc\\Cli\\Commands\\Cache\\StatsCommand'
		);

		// Route management commands
		$registry->register(
			'routes:list',
			'Neuron\\Mvc\\Cli\\Commands\\Routes\\ListCommand'
		);


		// Database migration commands
		$registry->register(
			'db:migration:generate',
			'Neuron\\Mvc\\Cli\\Commands\\Migrate\\CreateCommand'
		);

		$registry->register(
			'db:migrate',
			'Neuron\\Mvc\\Cli\\Commands\\Migrate\\RunCommand'
		);

		$registry->register(
			'db:rollback',
			'Neuron\\Mvc\\Cli\\Commands\\Migrate\\RollbackCommand'
		);

		$registry->register(
			'db:migrate:status',
			'Neuron\\Mvc\\Cli\\Commands\\Migrate\\StatusCommand'
		);

		$registry->register(
			'db:seed',
			'Neuron\\Mvc\\Cli\\Commands\\Migrate\\SeedCommand'
		);

		// Schema export commands
		$registry->register(
			'db:schema:dump',
			'Neuron\\Mvc\\Cli\\Commands\\Schema\\DumpCommand'
		);

		// Data export/import commands
		$registry->register(
			'db:data:dump',
			'Neuron\\Mvc\\Cli\\Commands\\Data\\DumpCommand'
		);

		$registry->register(
			'db:data:restore',
			'Neuron\\Mvc\\Cli\\Commands\\Data\\RestoreCommand'
		);
	}
}
