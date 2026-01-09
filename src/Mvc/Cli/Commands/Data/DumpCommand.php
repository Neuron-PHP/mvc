<?php

namespace Neuron\Mvc\Cli\Commands\Data;

use Neuron\Cli\Commands\Command;
use Neuron\Mvc\Database\MigrationManager;
use Neuron\Mvc\Database\DataExporter;
use Neuron\Mvc\Database\SqlWhereValidator;
use Neuron\Data\Settings\Source\Yaml;

/**
 * CLI command for exporting database data in various formats
 * Supports SQL, JSON, CSV, and YAML output formats
 */
class DumpCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'db:data:dump';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Export database data in various formats (SQL, JSON, CSV, YAML)';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		// Output options
		$this->addOption( 'output', 'o', true, 'Output file path (default: db/data_dump.sql)' );
		$this->addOption( 'format', 'f', true, 'Output format: sql, json, csv, yaml (default: sql)' );

		// Table selection options
		$this->addOption( 'tables', 't', true, 'Comma-separated list of tables to export (default: all)' );
		$this->addOption( 'exclude', 'e', true, 'Comma-separated list of tables to exclude' );

		// Data filtering options
		$this->addOption( 'limit', 'l', true, 'Limit number of rows per table' );
		$this->addOption(
			'where',
			'w',
			true,
			'WHERE conditions in format table:condition (can be used multiple times). ' .
			'⚠️  WARNING: WHERE clauses are validated for common SQL injection patterns but not fully sanitized. ' .
			'Only use with trusted input.'
		);

		// SQL-specific options
		$this->addOption( 'include-schema', null, false, 'Include CREATE TABLE statements in SQL dump' );
		$this->addOption( 'drop-tables', null, false, 'Include DROP TABLE statements in SQL dump' );
		$this->addOption( 'no-transaction', null, false, 'Do not wrap SQL dump in transaction' );

		// General options
		$this->addOption( 'compress', 'c', false, 'Compress output with gzip' );
		$this->addOption( 'config', null, true, 'Path to configuration directory' );
		$this->addOption( 'dry-run', null, false, 'Show what would be exported without actually exporting' );
		$this->addOption( 'verbose', 'v', false, 'Show detailed output including stack traces on error' );
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

		// Parse options
		$exportOptions = $this->parseExportOptions();

		// Show dry run info if requested
		if( $this->input->getOption( 'dry-run' ) )
		{
			return $this->performDryRun( $basePath, $settings, $exportOptions );
		}

		// Determine output path
		$outputPath = $this->determineOutputPath( $basePath, $exportOptions['format'] );

		// Create manager and exporter
		try
		{
			$this->output->info( 'Connecting to database...' );

			$manager = new MigrationManager( $basePath, $settings );

			$exporter = new DataExporter(
				$manager->getPhinxConfig(),
				$manager->getEnvironment(),
				$manager->getMigrationTable(),
				$exportOptions
			);

			// Handle CSV format specially (exports to directory)
			if( $exportOptions['format'] === DataExporter::FORMAT_CSV )
			{
				return $this->exportCsv( $exporter, $outputPath );
			}

			// Export data
			$this->output->info( 'Exporting data...' );
			$this->output->newLine();

			// Show progress for each table
			$tables = $this->getTableList( $exporter );
			$this->showExportProgress( $tables );

			// Perform export
			$startTime = microtime( true );
			$actualPath = $exporter->exportToFile( $outputPath );

			if( $actualPath !== false )
			{
				$endTime = microtime( true );
				$duration = round( $endTime - $startTime, 2 );

				// Get file size of the actual file written
				$fileSize = $this->formatFileSize( filesize( $actualPath ) );

				$this->output->newLine();
				$this->output->success( "Data exported successfully!" );
				$this->output->info( "Output file: {$actualPath}" );
				$this->output->info( "File size: {$fileSize}" );
				$this->output->info( "Export time: {$duration} seconds" );

				if( $exportOptions['compress'] )
				{
					$this->output->info( "Note: File was compressed with gzip" );
				}

				return 0;
			}
			else
			{
				$this->output->newLine();
				$this->output->error( 'Failed to export data to file: ' . $outputPath );
				return 1;
			}
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error exporting data: ' . $e->getMessage() );

			if( $this->input->getOption( 'verbose' ) || $this->input->getOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}

			return 1;
		}
	}

	/**
	 * Parse export options from command input
	 *
	 * @return array
	 */
	private function parseExportOptions(): array
	{
		$options = [];

		// Format
		$format = $this->input->getOption( 'format', 'sql' );
		$validFormats = ['sql', 'json', 'csv', 'yaml'];

		if( !in_array( $format, $validFormats ) )
		{
			$this->output->warning( "Invalid format '{$format}', using 'sql'" );
			$format = 'sql';
		}

		$options['format'] = $format;

		// Tables
		$tables = $this->input->getOption( 'tables' );
		if( $tables )
		{
			$options['tables'] = array_map( 'trim', explode( ',', $tables ) );
		}

		// Exclude
		$exclude = $this->input->getOption( 'exclude' );
		if( $exclude )
		{
			$options['exclude'] = array_map( 'trim', explode( ',', $exclude ) );
		}

		// Limit
		$limit = $this->input->getOption( 'limit' );
		if( $limit !== null )
		{
			$options['limit'] = (int)$limit;
		}

		// WHERE conditions
		$options['where'] = [];
		$whereOptions = $this->input->getOption( 'where' );
		if( $whereOptions )
		{
			// Handle multiple where options
			if( !is_array( $whereOptions ) )
			{
				$whereOptions = [$whereOptions];
			}

			foreach( $whereOptions as $where )
			{
				if( strpos( $where, ':' ) !== false )
				{
					list( $table, $condition ) = explode( ':', $where, 2 );
					$table = trim( $table );
					$condition = trim( $condition );

					// Validate WHERE clause for SQL injection attempts
					if( !SqlWhereValidator::isValid( $condition ) )
					{
						$this->output->error(
							"Potentially dangerous WHERE clause detected for table '{$table}': {$condition}"
						);
						$this->output->warning(
							"WHERE clauses must not contain SQL commands, comments, subqueries, or unbalanced quotes."
						);
						throw new \InvalidArgumentException( "Unsafe WHERE clause" );
					}

					$options['where'][$table] = $condition;
				}
			}
		}

		// SQL-specific options
		$options['include_schema'] = $this->input->getOption( 'include-schema', false );
		$options['drop_tables'] = $this->input->getOption( 'drop-tables', false );
		$options['use_transaction'] = !$this->input->getOption( 'no-transaction', false );

		// General options
		$options['compress'] = $this->input->getOption( 'compress', false );

		return $options;
	}

	/**
	 * Determine output path based on format
	 *
	 * @param string $basePath Base path
	 * @param string $format Output format
	 * @return string
	 */
	private function determineOutputPath( string $basePath, string $format ): string
	{
		$outputPath = $this->input->getOption( 'output' );

		if( !$outputPath )
		{
			// Default paths based on format
			$defaults = [
				'sql' => 'db/data_dump.sql',
				'json' => 'db/data_dump.json',
				'csv' => 'db/csv_export',
				'yaml' => 'db/data_dump.yaml'
			];

			$outputPath = $defaults[$format] ?? 'db/data_dump';
		}

		// Resolve relative paths
		if( !str_starts_with( $outputPath, '/' ) )
		{
			$outputPath = $basePath . '/' . $outputPath;
		}

		return $outputPath;
	}

	/**
	 * Perform dry run
	 *
	 * @param string $basePath Base path
	 * @param Yaml|null $settings Settings
	 * @param array $exportOptions Export options
	 * @return int
	 */
	private function performDryRun( string $basePath, ?Yaml $settings, array $exportOptions ): int
	{
		$this->output->info( "DRY RUN - No data will be exported" );
		$this->output->newLine();

		$this->output->info( "Export Configuration:" );
		$this->output->write( "  Format: " . $exportOptions['format'] );

		if( isset( $exportOptions['tables'] ) )
		{
			$this->output->write( "  Tables: " . implode( ', ', $exportOptions['tables'] ) );
		}
		else
		{
			$this->output->write( "  Tables: All tables" );
		}

		if( isset( $exportOptions['exclude'] ) && !empty( $exportOptions['exclude'] ) )
		{
			$this->output->write( "  Exclude: " . implode( ', ', $exportOptions['exclude'] ) );
		}

		if( isset( $exportOptions['limit'] ) )
		{
			$this->output->write( "  Row limit: " . $exportOptions['limit'] );
		}

		if( !empty( $exportOptions['where'] ) )
		{
			$this->output->write( "  WHERE conditions:" );
			foreach( $exportOptions['where'] as $table => $condition )
			{
				$this->output->write( "    {$table}: {$condition}" );
			}
		}

		if( $exportOptions['format'] === 'sql' )
		{
			$this->output->write( "  Include schema: " . ($exportOptions['include_schema'] ? 'Yes' : 'No') );
			$this->output->write( "  Drop tables: " . ($exportOptions['drop_tables'] ? 'Yes' : 'No') );
			$this->output->write( "  Use transaction: " . ($exportOptions['use_transaction'] ? 'Yes' : 'No') );
		}

		$this->output->write( "  Compress: " . ($exportOptions['compress'] ? 'Yes' : 'No') );

		$this->output->newLine();

		// Try to connect and show table info
		try
		{
			$manager = new MigrationManager( $basePath, $settings );
			$exporter = new DataExporter(
				$manager->getPhinxConfig(),
				$manager->getEnvironment(),
				$manager->getMigrationTable(),
				$exportOptions
			);

			$tables = $this->getTableList( $exporter );

			$this->output->info( "Tables to export (" . count( $tables ) . "):" );
			foreach( $tables as $table )
			{
				$this->output->write( "  - {$table}" );
			}

			$exporter->disconnect();
		}
		catch( \Exception $e )
		{
			$this->output->warning( "Could not connect to database to show table list" );
		}

		return 0;
	}

	/**
	 * Export to CSV format (directory)
	 *
	 * @param DataExporter $exporter
	 * @param string $outputPath
	 * @return int
	 */
	private function exportCsv( DataExporter $exporter, string $outputPath ): int
	{
		$this->output->info( "Exporting data to CSV files..." );
		$this->output->info( "Output directory: {$outputPath}" );
		$this->output->newLine();

		try
		{
			$startTime = microtime( true );
			$exportedFiles = $exporter->exportCsvToDirectory( $outputPath );
			$endTime = microtime( true );
			$duration = round( $endTime - $startTime, 2 );

			$this->output->success( "CSV export completed successfully!" );
			$this->output->info( "Exported " . count( $exportedFiles ) . " files:" );

			foreach( $exportedFiles as $file )
			{
				$fileSize = $this->formatFileSize( filesize( $file ) );
				$fileName = basename( $file );
				$this->output->write( "  - {$fileName} ({$fileSize})" );
			}

			$this->output->info( "Export time: {$duration} seconds" );

			return 0;
		}
		catch( \Exception $e )
		{
			$this->output->error( "Failed to export CSV files: " . $e->getMessage() );
			return 1;
		}
	}

	/**
	 * Get list of tables that will be exported
	 *
	 * @param DataExporter $exporter
	 * @return array
	 */
	private function getTableList( DataExporter $exporter ): array
	{
		// Call the public method directly
		return $exporter->getTableList();
	}

	/**
	 * Show export progress
	 *
	 * @param array $tables
	 */
	private function showExportProgress( array $tables ): void
	{
		$this->output->info( "Exporting " . count( $tables ) . " table(s):" );

		foreach( $tables as $table )
		{
			$this->output->write( "  - {$table}" );
		}

		$this->output->newLine();
	}

	/**
	 * Format file size for display
	 *
	 * @param int $bytes
	 * @return string
	 */
	private function formatFileSize( int $bytes ): string
	{
		$units = ['B', 'KB', 'MB', 'GB'];
		$unitIndex = 0;

		while( $bytes >= 1024 && $unitIndex < count( $units ) - 1 )
		{
			$bytes /= 1024;
			$unitIndex++;
		}

		return round( $bytes, 2 ) . ' ' . $units[$unitIndex];
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