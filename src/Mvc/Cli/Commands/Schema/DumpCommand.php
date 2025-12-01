<?php

namespace Neuron\Mvc\Cli\Commands\Schema;

use Neuron\Cli\Commands\Command;
use Neuron\Mvc\Database\MigrationManager;
use Neuron\Mvc\Database\SchemaExporter;
use Neuron\Data\Settings\Source\Yaml;

/**
 * CLI command for exporting database schema to YAML
 * Similar to Rails' rake db:schema:dump
 */
class DumpCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'db:schema:dump';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Export database schema to YAML file for reference';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'output', 'o', true, 'Output file path (default: db/schema.yaml)' );
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
			$this->output->info( 'Use --config to specify the configuration directory' );
			return 1;
		}

		// Load settings
		$settings = $this->loadSettings( $configPath );
		$basePath = dirname( $configPath );

		// Create manager
		$manager = new MigrationManager( $basePath, $settings );

		// Determine output file path
		$outputPath = $this->input->getOption( 'output' );
		if( !$outputPath )
		{
			// Check if there's a configured schema file path
			if( $settings )
			{
				$outputPath = $settings->get( 'migrations', 'schema_file' );
			}

			// Default to db/schema.yaml
			if( !$outputPath )
			{
				$outputPath = 'db/schema.yaml';
			}
		}

		// Resolve relative paths
		if( !str_starts_with( $outputPath, '/' ) )
		{
			$outputPath = $basePath . '/' . $outputPath;
		}

		try
		{
			$this->output->info( 'Exporting database schema...' );

			// Create schema exporter
			$exporter = new SchemaExporter(
				$manager->getPhinxConfig(),
				$manager->getEnvironment(),
				$manager->getMigrationTable()
			);

			// Export to file
			if( $exporter->exportToFile( $outputPath ) )
			{
				$this->output->newLine();
				$this->output->success( 'Schema exported successfully to: ' . $outputPath );
				return 0;
			}
			else
			{
				$this->output->newLine();
				$this->output->error( 'Failed to write schema file: ' . $outputPath );
				return 1;
			}
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error exporting schema: ' . $e->getMessage() );

			if( $this->input->hasOption( 'verbose' ) || $this->input->hasOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}

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
			$this->output->warning( 'Could not load configuration: ' . $e->getMessage() );
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
