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
				$fileSizeBytes = filesize( $actualPath );
				$fileSize = $fileSizeBytes !== false ? $this->formatFileSize( $fileSizeBytes ) : 'Unknown';

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

			if( $this->input->getOption( 'verbose' ) )
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
			$limit = (int)$limit;

			// Validate limit is greater than zero
			if( $limit <= 0 )
			{
				$this->output->error( "Invalid limit: {$limit}. Limit must be greater than 0." );
				throw new \InvalidArgumentException( "Limit must be greater than 0, got: {$limit}" );
			}

			$options['limit'] = $limit;
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
	 * Validates that the output path is within the allowed base directory
	 * to prevent path traversal attacks (e.g., --output ../../../etc/passwd)
	 *
	 * @param string $basePath Base path
	 * @param string $format Output format
	 * @return string
	 * @throws \InvalidArgumentException If path is outside allowed directory
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

		// Get canonical path of base directory to prevent path traversal attacks
		// Do this BEFORE prepending to relative paths to ensure consistency
		$resolvedBasePath = realpath( $basePath );

		// Check if realpath failed for base path (directory doesn't exist, unmounted, etc.)
		if( $resolvedBasePath === false )
		{
			if( isset( $this->output ) )
			{
				$this->output->error( "Base directory not found or inaccessible: {$basePath}" );
			}
			throw new \InvalidArgumentException( "Base directory not found: {$basePath}" );
		}

		// Resolve relative paths using the canonical base path
		// This ensures path comparison works correctly even with symlinks (e.g., /var -> /private/var)
		if( !str_starts_with( $outputPath, '/' ) )
		{
			$outputPath = $resolvedBasePath . '/' . $outputPath;
		}

		// For output files that don't exist yet, validate the parent directory
		$outputDir = dirname( $outputPath );

		// SECURITY: Validate path BEFORE creating any directories
		// This prevents attackers from creating arbitrary directories via path traversal

		// Normalize the output directory path by resolving .. and . components
		// This allows validation without requiring the path to exist
		$normalizedOutputDir = $this->normalizePath( $outputDir );

		// Check if the normalized output directory is within the allowed base path
		// Must either be a subdirectory of base or exactly the base path
		if( !str_starts_with( $normalizedOutputDir, $resolvedBasePath . '/' ) &&
		    $normalizedOutputDir !== $resolvedBasePath )
		{
			if( isset( $this->output ) )
			{
				$this->output->error( "Security error: Output path is outside the allowed directory" );
				$this->output->error( "Attempted path: {$outputPath}" );
			}
			throw new \InvalidArgumentException( "Output path is outside allowed directory" );
		}

		// Path is validated as safe (normalized check) - now we can create the directory if needed
		$resolvedOutputDir = realpath( $outputDir );

		if( $resolvedOutputDir === false )
		{
			// Directory doesn't exist, create it (we've already validated it's safe)
			if( !@mkdir( $outputDir, 0755, true ) )
			{
				if( isset( $this->output ) )
				{
					$this->output->error( "Output directory does not exist and could not be created: {$outputDir}" );
				}
				throw new \InvalidArgumentException( "Output directory not accessible: {$outputDir}" );
			}

			// Now get the real path after creating it
			$resolvedOutputDir = realpath( $outputDir );

			if( $resolvedOutputDir === false )
			{
				if( isset( $this->output ) )
				{
					$this->output->error( "Failed to resolve output directory path: {$outputDir}" );
				}
				throw new \InvalidArgumentException( "Failed to resolve output directory: {$outputDir}" );
			}
		}

		// SECURITY: Validate again after resolving symlinks
		// The normalized path check above prevents directory creation attacks,
		// but we must also check the resolved path to catch symlink-based traversal
		if( !str_starts_with( $resolvedOutputDir, $resolvedBasePath . '/' ) &&
		    $resolvedOutputDir !== $resolvedBasePath )
		{
			if( isset( $this->output ) )
			{
				$this->output->error( "Security error: Output path is outside the allowed directory" );
				$this->output->error( "Resolved path: {$resolvedOutputDir}" );
			}
			throw new \InvalidArgumentException( "Output path is outside allowed directory" );
		}

		// Reconstruct the validated output path using the resolved directory
		$outputFilename = basename( $outputPath );
		$validatedPath = $resolvedOutputDir . '/' . $outputFilename;

		return $validatedPath;
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
				$fileSizeBytes = filesize( $file );
				$fileSize = $fileSizeBytes !== false ? $this->formatFileSize( $fileSizeBytes ) : 'Unknown';
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
	 * Normalize a file path by resolving . and .. components
	 *
	 * This allows path validation without requiring the path to exist on disk.
	 * Critical for preventing directory creation before security validation.
	 *
	 * @param string $path Absolute path to normalize
	 * @return string Normalized absolute path
	 */
	private function normalizePath( string $path ): string
	{
		// This method expects an absolute path (after basePath has been prepended)
		// If not absolute, something is wrong with the calling code
		if( !str_starts_with( $path, '/' ) )
		{
			throw new \InvalidArgumentException( "normalizePath expects absolute path, got: {$path}" );
		}

		// Split path into components
		$parts = explode( '/', $path );
		$normalized = [];

		foreach( $parts as $part )
		{
			// Skip empty parts and current directory references
			if( $part === '' || $part === '.' )
			{
				continue;
			}

			// Handle parent directory references
			if( $part === '..' )
			{
				// Go up one level (remove last component) if possible
				if( !empty( $normalized ) )
				{
					array_pop( $normalized );
				}
				// If we try to go above root, just ignore it (can't go higher than /)
			}
			else
			{
				// Add normal path component
				$normalized[] = $part;
			}
		}

		// Reconstruct the path
		return '/' . implode( '/', $normalized );
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