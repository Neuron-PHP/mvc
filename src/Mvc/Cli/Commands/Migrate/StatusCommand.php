<?php

namespace Neuron\Mvc\Cli\Commands\Migrate;

use Neuron\Cli\Commands\Command;
use Neuron\Mvc\Database\MigrationManager;
use Neuron\Data\Setting\Source\Yaml;

/**
 * CLI command for showing migration status
 */
class StatusCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'db:migrate:status';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Show database migration status';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'format', 'f', true, 'Output format (text or json)' );
		$this->addOption( 'config', null, true, 'Path to configuration directory' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		// Get configuration
		$configPath = $this->input->getOption( 'config', $this->findConfigPath() );

		if( !$configPath || !is_dir( $configPath ) )
		{
			$this->output->error( 'Configuration directory not found: ' . ($configPath ?: 'none specified') );
			return 1;
		}

		// Load settings
		$settings = $this->loadSettings( $configPath );
		$basePath = dirname( $configPath );

		// Create manager
		$manager = new MigrationManager( $basePath, $settings );

		// Check if the migrations directory exists
		if( !is_dir( $manager->getMigrationsPath() ) )
		{
			$this->output->warning( 'Migrations directory not found: ' . $manager->getMigrationsPath() );
			$this->output->info( 'Run: neuron db:migration:generate YourFirstMigration' );
			return 1;
		}

		// Build Phinx command arguments
		$arguments = [
			'--environment' => $manager->getEnvironment()
		];

		// Add format if specified
		if( $format = $this->input->getOption( 'format' ) )
		{
			$arguments['--format'] = $format;
		}

		// Execute Phinx status command
		try
		{
			list( $exitCode, $output ) = $manager->execute( 'status', $arguments );

			// Display output
			$this->output->write( $output );

			return $exitCode;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error checking migration status: ' . $e->getMessage() );
			return 1;
		}
	}

	/**
	 * Load settings from config directory
	 *
	 * @param string $configPath
	 * @return Yaml|null
	 */
	private function loadSettings( string $configPath ): ?Yaml
	{
		$configFile = $configPath . '/neuron.yaml';

		if( !file_exists( $configFile ) )
		{
			return null;
		}

		try
		{
			return new Yaml( $configFile );
		}
		catch( \Exception $e )
		{
			return null;
		}
	}

	/**
	 * Try to find the configuration directory
	 *
	 * @return string|null
	 */
	private function findConfigPath(): ?string
	{
		$locations = [
			getcwd() . '/config',
			dirname( getcwd() ) . '/config',
			dirname( getcwd(), 2 ) . '/config',
		];

		foreach( $locations as $location )
		{
			if( is_dir( $location ) )
			{
				return $location;
			}
		}

		return null;
	}
}
