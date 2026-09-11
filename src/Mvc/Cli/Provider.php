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
