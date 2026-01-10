<?php

namespace Neuron\Mvc\Cli\Commands\Data;

use Neuron\Cli\Commands\Command;
use Neuron\Mvc\Database\MigrationManager;
use Neuron\Mvc\Database\DataImporter;
use Neuron\Data\Settings\Source\Yaml;

/**
 * CLI command for restoring database data from various formats
 * Supports SQL, JSON, CSV, and YAML input formats
 */
class RestoreCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'db:data:restore';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Restore database data from various formats (SQL, JSON, CSV, YAML)';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		// Input options
		$this->addOption( 'input', 'i', true, 'Input file path (required unless --dry-run)' );
		$this->addOption( 'format', 'f', true, 'Input format: sql, json, csv, yaml (auto-detected if not specified)' );

		// Table selection options
		$this->addOption( 'tables', 't', true, 'Comma-separated list of tables to restore (default: all in file)' );
		$this->addOption( 'exclude', 'e', true, 'Comma-separated list of tables to exclude' );

		// Conflict resolution options
		$this->addOption(
			'conflict-mode',
			null,
			true,
			'How to handle existing data: replace (default), append, skip'
		);
		$this->addOption( 'clear-tables', null, false, 'Clear all data before restore (same as --conflict-mode=replace)' );

		// Safety options
		$this->addOption( 'force', null, false, 'Skip confirmation prompt' );
		$this->addOption( 'confirm', 'c', false, 'Show confirmation prompt (default unless --force)' );
		$this->addOption( 'dry-run', null, false, 'Show what would be restored without actually restoring' );
		$this->addOption( 'backup-first', null, true, 'Create backup before restore (path to backup file)' );

		// Transaction options
		$this->addOption( 'no-transaction', null, false, 'Do not wrap restore in transaction' );
		$this->addOption( 'no-foreign-keys', null, false, 'Do not disable foreign key checks' );

		// Execution options
		$this->addOption( 'batch-size', 'b', true, 'Number of rows to insert per batch (default: 1000)' );
		$this->addOption( 'stop-on-error', null, false, 'Stop on first error (default: true)' );
		$this->addOption( 'continue-on-error', null, false, 'Continue even if errors occur' );

		// General options
		$this->addOption( 'config', null, true, 'Path to configuration directory' );
		$this->addOption( 'verify', null, false, 'Verify import after completion' );
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
		$importOptions = $this->parseImportOptions();

		// Check for input file (required unless dry-run)
		$inputPath = $this->input->getOption( 'input' );
		if( !$inputPath && !$this->input->getOption( 'dry-run' ) )
		{
			$this->output->error( 'Input file is required. Use --input to specify the file to restore from.' );
			return 1;
		}

		// Resolve and validate input path to prevent path traversal
		if( $inputPath )
		{
			// Resolve relative paths
			if( !str_starts_with( $inputPath, '/' ) )
			{
				$inputPath = $basePath . '/' . $inputPath;
			}

			// Get canonical paths to prevent path traversal attacks
			$resolvedBasePath = realpath( $basePath );
			$resolvedInputPath = realpath( $inputPath );

			// Check if realpath failed for base path (directory doesn't exist, unmounted, etc.)
			if( $resolvedBasePath === false )
			{
				$this->output->error( "Base directory not found or inaccessible: {$basePath}" );
				return 1;
			}

			// Check if realpath failed (file doesn't exist yet)
			// For input files, we need the file to exist
			if( $resolvedInputPath === false )
			{
				$this->output->error( "Input file not found: {$inputPath}" );
				return 1;
			}

			// Verify the resolved path is within the allowed base path
			// This prevents path traversal attacks using "../" sequences
			if( !str_starts_with( $resolvedInputPath, $resolvedBasePath . '/' ) &&
			    $resolvedInputPath !== $resolvedBasePath )
			{
				$this->output->error( "Security error: Input path is outside the allowed directory" );
				$this->output->error( "Attempted path: {$inputPath}" );
				return 1;
			}

			// Use the validated canonical path
			$inputPath = $resolvedInputPath;
		}

		// Show dry run info if requested
		if( $this->input->getOption( 'dry-run' ) )
		{
			return $this->performDryRun( $basePath, $settings, $importOptions, $inputPath );
		}

		// Backup first if requested
		if( $backupPath = $this->input->getOption( 'backup-first' ) )
		{
			if( !$this->createBackup( $basePath, $settings, $backupPath ) )
			{
				$this->output->error( 'Failed to create backup. Restore cancelled.' );
				return 1;
			}
		}

		// Confirm restore unless forced
		if( !$this->input->getOption( 'force' ) )
		{
			if( !$this->confirmRestore( $inputPath, $importOptions ) )
			{
				$this->output->info( 'Restore cancelled by user.' );
				return 0;
			}
		}

		// Create manager and importer
		try
		{
			$this->output->info( 'Connecting to database...' );

			$manager = new MigrationManager( $basePath, $settings );

			// Add progress callback
			$importOptions['progress_callback'] = function( $current, $total ) {
				// Simple progress indicator
				if( $total > 0 )
				{
					$percent = round( ($current / $total) * 100 );
					$this->output->write( "\rProgress: {$percent}% ({$current}/{$total})", false );
				}
			};

			$importer = new DataImporter(
				$manager->getPhinxConfig(),
				$manager->getEnvironment(),
				$manager->getMigrationTable(),
				$importOptions
			);

			// Perform restore
			$this->output->info( "Restoring data from: {$inputPath}" );
			$this->output->newLine();

			$startTime = microtime( true );

			// Handle CSV directory specially
			if( is_dir( $inputPath ) || $importOptions['format'] === DataImporter::FORMAT_CSV )
			{
				if( !is_dir( $inputPath ) )
				{
					$this->output->error( "CSV format requires a directory, not a file: {$inputPath}" );
					return 1;
				}

				$success = $importer->importFromCsvDirectory( $inputPath );
			}
			else
			{
				$success = $importer->importFromFile( $inputPath );
			}

			$endTime = microtime( true );
			$duration = round( $endTime - $startTime, 2 );

			// Clear progress line
			$this->output->write( "\r" . str_repeat( ' ', 80 ) . "\r", false );

			// Get statistics
			$stats = $importer->getStatistics();
			$errors = $importer->getErrors();

			// Display results
			$this->output->newLine();

			if( $success )
			{
				$this->output->success( "Data restored successfully!" );
				$this->output->info( "Tables imported: {$stats['tables_imported']}" );
				$this->output->info( "Rows imported: {$stats['rows_imported']}" );
				$this->output->info( "Restore time: {$duration} seconds" );

				// Verify if requested
				if( $this->input->getOption( 'verify' ) )
				{
					$this->verifyRestore( $importer );
				}

				return 0;
			}
			else
			{
				$this->output->error( "Restore completed with errors!" );
				$this->output->info( "Tables imported: {$stats['tables_imported']}" );
				$this->output->info( "Rows imported: {$stats['rows_imported']}" );
				$this->output->warning( "Errors encountered: {$stats['errors']}" );

				// Show first few errors
				if( !empty( $errors ) )
				{
					$this->output->newLine();
					$this->output->error( "Errors:" );
					foreach( array_slice( $errors, 0, 5 ) as $error )
					{
						$this->output->write( "  - " . $error );
					}

					if( count( $errors ) > 5 )
					{
						$remaining = count( $errors ) - 5;
						$this->output->write( "  ... and {$remaining} more errors" );
					}
				}

				return 1;
			}
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error restoring data: ' . $e->getMessage() );

			if( $this->input->getOption( 'verbose' ) || $this->input->getOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}

			return 1;
		}
	}

	/**
	 * Parse import options from command input
	 *
	 * @return array
	 */
	private function parseImportOptions(): array
	{
		$options = [];

		// Format
		$format = $this->input->getOption( 'format' );
		if( $format )
		{
			$validFormats = ['sql', 'json', 'csv', 'yaml'];
			if( !in_array( $format, $validFormats ) )
			{
				$this->output->warning( "Invalid format '{$format}', will auto-detect" );
				$format = null;
			}
		}
		$options['format'] = $format; // null means auto-detect

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

		// Conflict mode
		$conflictMode = $this->input->getOption( 'conflict-mode', 'replace' );
		if( $this->input->getOption( 'clear-tables' ) )
		{
			$conflictMode = 'replace';
		}

		$validModes = ['replace', 'append', 'skip'];
		if( !in_array( $conflictMode, $validModes ) )
		{
			$this->output->warning( "Invalid conflict mode '{$conflictMode}', using 'replace'" );
			$conflictMode = 'replace';
		}
		$options['conflict_mode'] = $conflictMode;

		// Transaction options
		$options['use_transaction'] = !$this->input->getOption( 'no-transaction', false );
		$options['disable_foreign_keys'] = !$this->input->getOption( 'no-foreign-keys', false );

		// Batch size
		$batchSize = $this->input->getOption( 'batch-size', 1000 );
		$batchSize = (int)$batchSize;

		// Validate batch size is greater than zero
		if( $batchSize <= 0 )
		{
			$this->output->error( "Invalid batch size: {$batchSize}. Batch size must be greater than 0." );
			throw new \InvalidArgumentException( "Batch size must be greater than 0, got: {$batchSize}" );
		}

		$options['batch_size'] = $batchSize;

		// Error handling
		if( $this->input->getOption( 'continue-on-error' ) )
		{
			$options['stop_on_error'] = false;
		}
		else
		{
			$options['stop_on_error'] = $this->input->getOption( 'stop-on-error', true );
		}

		return $options;
	}

	/**
	 * Perform dry run
	 *
	 * @param string $basePath Base path
	 * @param Yaml|null $settings Settings
	 * @param array $importOptions Import options
	 * @param string|null $inputPath Input file path
	 * @return int
	 */
	private function performDryRun( string $basePath, ?Yaml $settings, array $importOptions, ?string $inputPath ): int
	{
		$this->output->info( "DRY RUN - No data will be restored" );
		$this->output->newLine();

		$this->output->info( "Restore Configuration:" );

		if( $inputPath )
		{
			$this->output->write( "  Input file: " . $inputPath );

			// Check file info
			if( file_exists( $inputPath ) )
			{
				if( is_dir( $inputPath ) )
				{
					$files = glob( $inputPath . '/*.csv' );
					$this->output->write( "  CSV files found: " . count( $files ) );
				}
				else
				{
					$fileSizeBytes = filesize( $inputPath );
					$size = $fileSizeBytes !== false ? $this->formatFileSize( $fileSizeBytes ) : 'Unknown';
					$this->output->write( "  File size: " . $size );
				}
			}
			else
			{
				$this->output->warning( "  Input file does not exist!" );
			}
		}
		else
		{
			$this->output->write( "  Input file: Not specified" );
		}

		$this->output->write( "  Format: " . ($importOptions['format'] ?: 'auto-detect') );

		if( isset( $importOptions['tables'] ) )
		{
			$this->output->write( "  Tables: " . implode( ', ', $importOptions['tables'] ) );
		}
		else
		{
			$this->output->write( "  Tables: All tables in input file" );
		}

		if( isset( $importOptions['exclude'] ) && !empty( $importOptions['exclude'] ) )
		{
			$this->output->write( "  Exclude: " . implode( ', ', $importOptions['exclude'] ) );
		}

		$this->output->write( "  Conflict mode: " . $importOptions['conflict_mode'] );
		$this->output->write( "  Use transaction: " . ($importOptions['use_transaction'] ? 'Yes' : 'No') );
		$this->output->write( "  Disable foreign keys: " . ($importOptions['disable_foreign_keys'] ? 'Yes' : 'No') );
		$this->output->write( "  Batch size: " . $importOptions['batch_size'] );
		$this->output->write( "  Stop on error: " . ($importOptions['stop_on_error'] ? 'Yes' : 'No') );

		$this->output->newLine();

		// Try to connect and show current state
		try
		{
			$manager = new MigrationManager( $basePath, $settings );
			$importer = new DataImporter(
				$manager->getPhinxConfig(),
				$manager->getEnvironment(),
				$manager->getMigrationTable(),
				$importOptions
			);

			// Get current table row counts
			$this->output->info( "Current database state:" );
			$tables = $this->getDatabaseTables( $importer );

			if( empty( $tables ) )
			{
				$this->output->write( "  No tables found or cannot connect to database" );
			}
			else
			{
				foreach( $tables as $table => $count )
				{
					$this->output->write( "  {$table}: {$count} rows" );
				}
			}

			$importer->disconnect();
		}
		catch( \Exception $e )
		{
			$this->output->warning( "Could not connect to database: " . $e->getMessage() );
		}

		return 0;
	}

	/**
	 * Confirm restore with user
	 *
	 * @param string $inputPath Input file path
	 * @param array $importOptions Import options
	 * @return bool
	 */
	private function confirmRestore( string $inputPath, array $importOptions ): bool
	{
		$this->output->warning( "WARNING: This will modify your database!" );
		$this->output->newLine();

		$this->output->info( "You are about to restore data from:" );
		$this->output->write( "  " . $inputPath );
		$this->output->newLine();

		if( $importOptions['conflict_mode'] === 'replace' )
		{
			$this->output->warning( "This will REPLACE existing data in affected tables!" );
		}
		elseif( $importOptions['conflict_mode'] === 'append' )
		{
			$this->output->info( "This will APPEND to existing data in affected tables." );
		}
		else
		{
			$this->output->info( "This will SKIP tables that already contain data." );
		}

		$this->output->newLine();

		// Ask for confirmation
		$question = "Do you want to continue? (yes/no) [no]: ";
		$answer = $this->inputReader->read( $question );

		return strtolower( trim( $answer ) ) === 'yes' || strtolower( trim( $answer ) ) === 'y';
	}

	/**
	 * Create backup before restore
	 *
	 * @param string $basePath Base path
	 * @param Yaml|null $settings Settings
	 * @param string $backupPath Backup file path
	 * @return bool Success status
	 */
	private function createBackup( string $basePath, ?Yaml $settings, string $backupPath ): bool
	{
		$this->output->info( "Creating backup before restore..." );

		try
		{
			// Use DataExporter to create backup
			$manager = new MigrationManager( $basePath, $settings );

			// Import DataExporter class
			$exporterClass = 'Neuron\\Mvc\\Database\\DataExporter';
			if( !class_exists( $exporterClass ) )
			{
				$this->output->error( "DataExporter class not found. Cannot create backup." );
				return false;
			}

			$exporter = new $exporterClass(
				$manager->getPhinxConfig(),
				$manager->getEnvironment(),
				$manager->getMigrationTable(),
				['format' => 'sql', 'use_transaction' => true]
			);

			// Resolve and validate backup path to prevent path traversal
			if( !str_starts_with( $backupPath, '/' ) )
			{
				$backupPath = $basePath . '/' . $backupPath;
			}

			// Get canonical paths to prevent path traversal attacks
			$resolvedBasePath = realpath( $basePath );

			// Check if realpath failed for base path (directory doesn't exist, unmounted, etc.)
			if( $resolvedBasePath === false )
			{
				$this->output->error( "Base directory not found or inaccessible: {$basePath}" );
				return false;
			}

			// For backup files, the file may not exist yet, so we check the parent directory
			$backupDir = dirname( $backupPath );
			$resolvedBackupDir = realpath( $backupDir );

			if( $resolvedBackupDir === false )
			{
				$this->output->error( "Backup directory does not exist: {$backupDir}" );
				return false;
			}

			// Verify the backup directory is within the allowed base path
			if( !str_starts_with( $resolvedBackupDir, $resolvedBasePath . '/' ) &&
			    $resolvedBackupDir !== $resolvedBasePath )
			{
				$this->output->error( "Security error: Backup path is outside the allowed directory" );
				$this->output->error( "Attempted path: {$backupPath}" );
				return false;
			}

			// Reconstruct the validated backup path
			$backupFilename = basename( $backupPath );
			$backupPath = $resolvedBackupDir . '/' . $backupFilename;

			if( $exporter->exportToFile( $backupPath ) )
			{
				$fileSizeBytes = filesize( $backupPath );
				$size = $fileSizeBytes !== false ? $this->formatFileSize( $fileSizeBytes ) : 'Unknown';
				$this->output->success( "Backup created: {$backupPath} ({$size})" );
				return true;
			}
			else
			{
				$this->output->error( "Failed to create backup file" );
				return false;
			}
		}
		catch( \Exception $e )
		{
			$this->output->error( "Error creating backup: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Verify restore by checking row counts
	 *
	 * @param DataImporter $importer
	 */
	private function verifyRestore( DataImporter $importer ): void
	{
		$this->output->newLine();
		$this->output->info( "Verifying restore..." );

		// Get statistics from import
		$stats = $importer->getStatistics();

		$this->output->write( "  Total rows imported: " . $stats['rows_imported'] );
		$this->output->write( "  Total tables imported: " . $stats['tables_imported'] );

		if( $stats['errors'] > 0 )
		{
			$this->output->warning( "  Errors encountered: " . $stats['errors'] );
		}
		else
		{
			$this->output->success( "  No errors encountered" );
		}
	}

	/**
	 * Get database tables and row counts
	 *
	 * @param DataImporter $importer
	 * @return array Table => row count
	 */
	private function getDatabaseTables( DataImporter $importer ): array
	{
		// Call the public method directly
		return $importer->getTableRowCounts();
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