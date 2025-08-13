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
			'mvc:cache:clear', 
			'Neuron\\Mvc\\Cli\\Commands\\Cache\\ClearCommand' 
		);
		
		$registry->register( 
			'mvc:cache:stats', 
			'Neuron\\Mvc\\Cli\\Commands\\Cache\\StatsCommand' 
		);
		
		// Route management commands
		$registry->register( 
			'mvc:routes:list', 
			'Neuron\\Mvc\\Cli\\Commands\\Routes\\ListCommand' 
		);
		
		// Future commands can be added here:
		// $registry->register( 'mvc:routes:test', 'Neuron\\Mvc\\Cli\\Commands\\Routes\\TestCommand' );
		// $registry->register( 'mvc:controller:create', 'Neuron\\Mvc\\Cli\\Commands\\Controller\\CreateCommand' );
		// $registry->register( 'mvc:view:create', 'Neuron\\Mvc\\Cli\\Commands\\View\\CreateCommand' );
		// $registry->register( 'mvc:serve', 'Neuron\\Mvc\\Cli\\Commands\\ServeCommand' );
	}
}