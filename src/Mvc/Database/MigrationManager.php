<?php

namespace Neuron\Mvc\Database;

use Neuron\Data\Settings\Source\ISettingSource;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Manages database migrations using Phinx
 * Bridges Neuron configuration to Phinx
 */
class MigrationManager
{
	private string $_BasePath;
	private ?ISettingSource $_SettingSource;
	private ?Config $_PhinxConfig = null;

	/**
	 * @param string $BasePath Application base path
	 * @param ISettingSource|null $SettingSource Neuron settings source
	 */
	public function __construct( string $BasePath, ?ISettingSource $SettingSource = null )
	{
		$this->_BasePath = rtrim( $BasePath, '/' );
		$this->_SettingSource = $SettingSource;
	}

	/**
	 * Get Phinx configuration from Neuron settings
	 *
	 * @return Config
	 */
	public function getPhinxConfig(): Config
	{
		if( $this->_PhinxConfig !== null )
		{
			return $this->_PhinxConfig;
		}

		$config = $this->buildPhinxConfig();
		$this->_PhinxConfig = new Config( $config );

		return $this->_PhinxConfig;
	}

	/**
	 * Build Phinx configuration array from Neuron settings
	 *
	 * @return array
	 */
	private function buildPhinxConfig(): array
	{
		$migrationsPath = $this->getMigrationsPath();
		$seedsPath = $this->getSeedsPath();
		$migrationTable = $this->getMigrationTable();

		// Build paths array
		$paths = [
			'migrations' => $migrationsPath,
			'seeds' => $seedsPath
		];

		// Build environments configuration
		$environments = [
			'default_migration_table' => $migrationTable,
			'default_environment' => $this->getEnvironment(),
			$this->getEnvironment() => $this->getDatabaseConfig()
		];

		return [
			'paths' => $paths,
			'environments' => $environments,
			'version_order' => 'creation'
		];
	}

	/**
	 * Get database configuration for Phinx
	 *
	 * @return array
	 */
	private function getDatabaseConfig(): array
	{
		if( !$this->_SettingSource )
		{
			return $this->getDefaultDatabaseConfig();
		}

		try
		{
			$adapter = $this->getSetting( 'database', 'adapter', 'mysql' );
			$name = $this->getSetting( 'database', 'name', 'neuron_cms' );

			// For SQLite, remove .sqlite3 suffix as Phinx will append it
			if( $adapter === 'sqlite' && str_ends_with( $name, '.sqlite3' ) )
			{
				$name = substr( $name, 0, -8 ); // Remove .sqlite3
			}

			$host = $this->getSetting( 'database', 'host', 'localhost' );
			$user = $this->getSetting( 'database', 'user', 'root' );
			$pass = $this->getSetting( 'database', 'pass', '' );
			$port = $this->getSetting( 'database', 'port', 3306 );
			$charset = $this->getSetting( 'database', 'charset', 'utf8mb4' );

			return [
				'adapter' => $adapter,
				'host' => $host,
				'name' => $name,
				'user' => $user,
				'pass' => $pass,
				'port' => (int)$port,
				'charset' => $charset
			];
		}
		catch( \Exception $e )
		{
			return $this->getDefaultDatabaseConfig();
		}
	}

	/**
	 * Get default database configuration
	 *
	 * @return array
	 */
	private function getDefaultDatabaseConfig(): array
	{
		return [
			'adapter' => 'mysql',
			'host' => 'localhost',
			'name' => 'neuron_cms',
			'user' => 'root',
			'pass' => '',
			'port' => 3306,
			'charset' => 'utf8mb4'
		];
	}

	/**
	 * Get migrations directory path
	 *
	 * @return string
	 */
	public function getMigrationsPath(): string
	{
		$path = $this->getSetting( 'migrations', 'path', 'db/migrate' );

		return $this->resolvePath( $path );
	}

	/**
	 * Get seeds directory path
	 *
	 * @return string
	 */
	public function getSeedsPath(): string
	{
		$path = $this->getSetting( 'migrations', 'seeds_path', 'db/seed' );

		return $this->resolvePath( $path );
	}

	/**
	 * Get migration tracking table name
	 *
	 * @return string
	 */
	public function getMigrationTable(): string
	{
		return $this->getSetting( 'migrations', 'table', 'phinx_log' );
	}

	/**
	 * Get environment name
	 *
	 * @return string
	 */
	public function getEnvironment(): string
	{
		return $this->getSetting( 'system', 'environment', 'development' );
	}

	/**
	 * Resolve path relative to base path
	 *
	 * @param string $path
	 * @return string
	 */
	private function resolvePath( string $path ): string
	{
		// If absolute path, use as-is
		if( str_starts_with( $path, '/' ) )
		{
			return $path;
		}

		// Relative to base path
		return $this->_BasePath . '/' . $path;
	}

	/**
	 * Get setting value
	 *
	 * @param string $section
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	private function getSetting( string $section, string $key, mixed $default ): mixed
	{
		if( !$this->_SettingSource )
		{
			return $default;
		}

		try
		{
			$value = $this->_SettingSource->get( $section, $key );
			return $value ?? $default;
		}
		catch( \Exception $e )
		{
			return $default;
		}
	}

	/**
	 * Ensure migrations directory exists
	 *
	 * @return bool
	 */
	public function ensureMigrationsDirectory(): bool
	{
		$path = $this->getMigrationsPath();

		if( !is_dir( $path ) )
		{
			return mkdir( $path, 0755, true );
		}

		return true;
	}

	/**
	 * Ensure seeds directory exists
	 *
	 * @return bool
	 */
	public function ensureSeedsDirectory(): bool
	{
		$path = $this->getSeedsPath();

		if( !is_dir( $path ) )
		{
			return mkdir( $path, 0755, true );
		}

		return true;
	}

	/**
	 * Get schema file path
	 *
	 * @return string
	 */
	public function getSchemaFilePath(): string
	{
		$path = $this->getSetting( 'migrations', 'schema_file', 'db/schema.yaml' );

		return $this->resolvePath( $path );
	}

	/**
	 * Check if auto-dump schema is enabled
	 *
	 * @return bool
	 */
	public function isAutoDumpSchemaEnabled(): bool
	{
		return (bool)$this->getSetting( 'migrations', 'auto_dump_schema', false );
	}

	/**
	 * Dump database schema to YAML file
	 *
	 * @param string|null $outputPath Optional output path (uses configured path if null)
	 * @return bool Success status
	 */
	public function dumpSchema( ?string $outputPath = null ): bool
	{
		try
		{
			$exporter = new SchemaExporter(
				$this->getPhinxConfig(),
				$this->getEnvironment(),
				$this->getMigrationTable()
			);

			$path = $outputPath ?? $this->getSchemaFilePath();

			return $exporter->exportToFile( $path );
		}
		catch( \Exception $e )
		{
			// Log error but don't fail the migration
			error_log( "Failed to dump schema: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Execute a Phinx command
	 *
	 * @param string $command Command name (migrate, rollback, status, etc.)
	 * @param array $arguments Command arguments
	 * @return array [exitCode, output]
	 */
	public function execute( string $command, array $arguments = [] ): array
	{
		// Get environment name
		$environment = $arguments['--environment'] ?? $this->getEnvironment();

		// Create output buffer
		$output = new BufferedOutput();

		// Create Phinx Manager with our config
		$manager = new Manager(
			$this->getPhinxConfig(),
			new StringInput( '' ),
			$output
		);

		try
		{
			switch( $command )
			{
				case 'migrate':
					$target = $arguments['--target'] ?? null;
					$date = $arguments['--date'] ?? null;
					$fake = $arguments['--fake'] ?? false;

					// Run migration and capture output
					$manager->migrate( $environment, $target, $fake );

					// Get the output from the manager
					$result = $output->fetch();

					// If no output from Phinx, add a success message
					if( empty( trim( $result ) ) )
					{
						$result = "All migrations have been run\n";
					}

					// Auto-dump schema if enabled and not a fake migration
					if( !$fake && $this->isAutoDumpSchemaEnabled() )
					{
						$this->dumpSchema();
					}

					return [0, $result];

				case 'rollback':
					$target = $arguments['--target'] ?? null;
					$date = $arguments['--date'] ?? null;
					$force = $arguments['--force'] ?? false;
					$fake = $arguments['--fake'] ?? false;

					$manager->rollback( $environment, $target, $force, $fake );

					$result = $output->fetch();
					if( empty( trim( $result ) ) )
					{
						$result = "Rollback completed successfully\n";
					}

					// Auto-dump schema if enabled and not a fake rollback
					if( !$fake && $this->isAutoDumpSchemaEnabled() )
					{
						$this->dumpSchema();
					}

					return [0, $result];

				case 'status':
					$manager->printStatus( $environment );
					return [0, $output->fetch()];

				default:
					return [1, "Unknown command: $command\n"];
			}
		}
		catch( \Exception $e )
		{
			return [1, "Error: " . $e->getMessage() . "\n"];
		}
	}
}
