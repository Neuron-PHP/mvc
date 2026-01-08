<?php

namespace Neuron\Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports database data from various formats
 * Supports SQL, JSON, CSV, and YAML input formats
 */
class DataImporter
{
	private AdapterInterface $_Adapter;
	private string $_MigrationTable;
	private string $_AdapterType;
	private IFileSystem $fs;
	private array $_Options;
	private array $_Errors = [];
	private int $_RowsImported = 0;
	private int $_TablesImported = 0;

	// Input format constants (matching DataExporter)
	const FORMAT_SQL = 'sql';
	const FORMAT_JSON = 'json';
	const FORMAT_CSV = 'csv';
	const FORMAT_YAML = 'yaml';

	// Conflict resolution modes
	const CONFLICT_REPLACE = 'replace';  // Clear table and insert new data
	const CONFLICT_APPEND = 'append';    // Keep existing data and add new
	const CONFLICT_SKIP = 'skip';        // Skip if table has data

	/**
	 * @param Config $PhinxConfig Phinx configuration
	 * @param string $Environment Environment name
	 * @param string $MigrationTable Migration tracking table name
	 * @param array $Options Import options
	 * @param IFileSystem|null $fs File system implementation (null = use real file system)
	 */
	public function __construct(
		Config $PhinxConfig,
		string $Environment,
		string $MigrationTable = 'phinx_log',
		array $Options = [],
		?IFileSystem $fs = null
	)
	{
		$this->_MigrationTable = $MigrationTable;
		$this->_Options = array_merge([
			'format' => self::FORMAT_SQL,
			'tables' => null, // null = all tables in import file
			'exclude' => [],
			'clear_tables' => false, // Clear all data before import
			'disable_foreign_keys' => true,
			'use_transaction' => true,
			'batch_size' => 1000, // For batch inserts
			'conflict_mode' => self::CONFLICT_REPLACE,
			'validate_data' => true,
			'stop_on_error' => true,
			'progress_callback' => null // Callback for progress updates
		], $Options);

		$this->fs = $fs ?? new RealFileSystem();

		// Create database adapter from Phinx config
		$options = $PhinxConfig->getEnvironment( $Environment );
		$this->_Adapter = AdapterFactory::instance()->getAdapter(
			$options['adapter'],
			$options
		);

		// Connect to database
		$this->_Adapter->connect();

		// Store adapter type
		$this->_AdapterType = $this->_Adapter->getAdapterType();
	}

	/**
	 * Import data from string
	 *
	 * @param string $data Data to import
	 * @return bool Success status
	 */
	public function import( string $data ): bool
	{
		$this->_Errors = [];
		$this->_RowsImported = 0;
		$this->_TablesImported = 0;

		try
		{
			// Begin transaction if requested
			if( $this->_Options['use_transaction'] )
			{
				$this->_Adapter->beginTransaction();
			}

			// Disable foreign key checks if requested
			if( $this->_Options['disable_foreign_keys'] )
			{
				$this->disableForeignKeyChecks();
			}

			// Import based on format
			$success = false;
			switch( $this->_Options['format'] )
			{
				case self::FORMAT_SQL:
					$success = $this->importFromSql( $data );
					break;
				case self::FORMAT_JSON:
					$success = $this->importFromJson( $data );
					break;
				case self::FORMAT_YAML:
					$success = $this->importFromYaml( $data );
					break;
				case self::FORMAT_CSV:
					// CSV requires file/directory path, not string data
					throw new \InvalidArgumentException(
						'CSV format requires importFromCsvDirectory() method'
					);
				default:
					throw new \InvalidArgumentException(
						"Unsupported format: {$this->_Options['format']}"
					);
			}

			// Re-enable foreign key checks
			if( $this->_Options['disable_foreign_keys'] )
			{
				$this->enableForeignKeyChecks();
			}

			// Commit or rollback transaction
			if( $this->_Options['use_transaction'] )
			{
				if( $success )
				{
					$this->_Adapter->commitTransaction();
				}
				else
				{
					$this->_Adapter->rollbackTransaction();
				}
			}

			return $success;
		}
		catch( \Exception $e )
		{
			$this->_Errors[] = $e->getMessage();

			// Rollback on error
			if( $this->_Options['use_transaction'] && $this->_Adapter->hasTransaction() )
			{
				$this->_Adapter->rollbackTransaction();
			}

			// Re-enable foreign key checks
			if( $this->_Options['disable_foreign_keys'] )
			{
				try
				{
					$this->enableForeignKeyChecks();
				}
				catch( \Exception $fkException )
				{
					// Ignore errors when re-enabling
				}
			}

			if( $this->_Options['stop_on_error'] )
			{
				throw $e;
			}

			return false;
		}
	}

	/**
	 * Import data from file
	 *
	 * @param string $FilePath Path to input file
	 * @return bool Success status
	 */
	public function importFromFile( string $FilePath ): bool
	{
		if( !$this->fs->fileExists( $FilePath ) )
		{
			throw new \InvalidArgumentException( "File not found: {$FilePath}" );
		}

		// Handle compressed files
		$isCompressed = str_ends_with( $FilePath, '.gz' );

		if( $isCompressed )
		{
			$data = gzdecode( $this->fs->readFile( $FilePath ) );
			if( $data === false )
			{
				throw new \RuntimeException( "Failed to decompress file: {$FilePath}" );
			}
		}
		else
		{
			$data = $this->fs->readFile( $FilePath );
		}

		// Auto-detect format if not specified
		if( $this->_Options['format'] === null )
		{
			$this->_Options['format'] = $this->detectFormat( $FilePath, $data );
		}

		return $this->import( $data );
	}

	/**
	 * Import data from SQL format
	 *
	 * @param string $sql SQL data
	 * @return bool Success status
	 */
	private function importFromSql( string $sql ): bool
	{
		// Split SQL into individual statements
		$statements = $this->splitSqlStatements( $sql );

		$totalStatements = count( $statements );
		$executedStatements = 0;

		foreach( $statements as $statement )
		{
			$statement = trim( $statement );

			// Skip empty statements and comments
			if( empty( $statement ) || str_starts_with( $statement, '--' ) )
			{
				continue;
			}

			try
			{
				// Check if this is a table we should process
				if( !$this->shouldProcessStatement( $statement ) )
				{
					continue;
				}

				// Execute the statement
				$this->_Adapter->execute( $statement );
				$executedStatements++;

				// Track INSERT statements
				if( stripos( $statement, 'INSERT INTO' ) === 0 )
				{
					$this->_RowsImported += $this->estimateRowsFromInsert( $statement );
				}

				// Progress callback
				if( $this->_Options['progress_callback'] )
				{
					call_user_func(
						$this->_Options['progress_callback'],
						$executedStatements,
						$totalStatements
					);
				}
			}
			catch( \Exception $e )
			{
				$this->_Errors[] = "SQL Error: " . $e->getMessage() . " in statement: " .
				                   substr( $statement, 0, 100 ) . "...";

				if( $this->_Options['stop_on_error'] )
				{
					throw $e;
				}
			}
		}

		return empty( $this->_Errors );
	}

	/**
	 * Import data from JSON format
	 *
	 * @param string $json JSON data
	 * @return bool Success status
	 */
	private function importFromJson( string $json ): bool
	{
		$data = json_decode( $json, true );

		if( json_last_error() !== JSON_ERROR_NONE )
		{
			throw new \InvalidArgumentException( 'Invalid JSON: ' . json_last_error_msg() );
		}

		if( !isset( $data['data'] ) )
		{
			throw new \InvalidArgumentException( 'Invalid JSON structure: missing "data" key' );
		}

		return $this->importStructuredData( $data['data'] );
	}

	/**
	 * Import data from YAML format
	 *
	 * @param string $yaml YAML data
	 * @return bool Success status
	 */
	private function importFromYaml( string $yaml ): bool
	{
		$data = Yaml::parse( $yaml );

		if( !isset( $data['data'] ) )
		{
			throw new \InvalidArgumentException( 'Invalid YAML structure: missing "data" key' );
		}

		return $this->importStructuredData( $data['data'] );
	}

	/**
	 * Import structured data (from JSON/YAML)
	 *
	 * @param array $data Structured data with table names as keys
	 * @return bool Success status
	 */
	private function importStructuredData( array $data ): bool
	{
		$tables = $this->filterTables( array_keys( $data ) );

		foreach( $tables as $table )
		{
			if( !isset( $data[$table] ) )
			{
				continue;
			}

			$tableData = $data[$table];

			// Handle both formats: direct array of rows or object with 'rows' key
			if( isset( $tableData['rows'] ) )
			{
				$rows = $tableData['rows'];
			}
			else
			{
				$rows = $tableData;
			}

			if( empty( $rows ) )
			{
				continue;
			}

			try
			{
				// Handle conflict resolution
				if( !$this->prepareTableForImport( $table ) )
				{
					continue;
				}

				// Import rows in batches
				$this->importTableData( $table, $rows );
				$this->_TablesImported++;
			}
			catch( \Exception $e )
			{
				$this->_Errors[] = "Error importing table {$table}: " . $e->getMessage();

				if( $this->_Options['stop_on_error'] )
				{
					throw $e;
				}
			}
		}

		return empty( $this->_Errors );
	}

	/**
	 * Import all CSV files from a directory
	 *
	 * @param string $DirectoryPath Directory containing CSV files
	 * @return bool Success status
	 */
	public function importFromCsvDirectory( string $DirectoryPath ): bool
	{
		if( !$this->fs->isDir( $DirectoryPath ) )
		{
			throw new \InvalidArgumentException( "Directory not found: {$DirectoryPath}" );
		}

		// Read metadata if available
		$metadataFile = $DirectoryPath . '/export_metadata.json';
		$metadata = [];

		if( $this->fs->fileExists( $metadataFile ) )
		{
			$metadata = json_decode( $this->fs->readFile( $metadataFile ), true );
		}

		// Find all CSV files
		$files = glob( $DirectoryPath . '/*.csv' );

		if( empty( $files ) )
		{
			throw new \InvalidArgumentException( "No CSV files found in directory: {$DirectoryPath}" );
		}

		$this->_Errors = [];
		$this->_RowsImported = 0;
		$this->_TablesImported = 0;

		try
		{
			// Begin transaction if requested
			if( $this->_Options['use_transaction'] )
			{
				$this->_Adapter->beginTransaction();
			}

			// Disable foreign key checks if requested
			if( $this->_Options['disable_foreign_keys'] )
			{
				$this->disableForeignKeyChecks();
			}

			foreach( $files as $file )
			{
				$tableName = pathinfo( $file, PATHINFO_FILENAME );

				// Skip if not in table filter
				if( !$this->shouldProcessTable( $tableName ) )
				{
					continue;
				}

				try
				{
					$this->importCsvFile( $file, $tableName );
					$this->_TablesImported++;
				}
				catch( \Exception $e )
				{
					$this->_Errors[] = "Error importing {$tableName}: " . $e->getMessage();

					if( $this->_Options['stop_on_error'] )
					{
						throw $e;
					}
				}
			}

			// Re-enable foreign key checks
			if( $this->_Options['disable_foreign_keys'] )
			{
				$this->enableForeignKeyChecks();
			}

			// Commit transaction
			if( $this->_Options['use_transaction'] )
			{
				if( empty( $this->_Errors ) )
				{
					$this->_Adapter->commitTransaction();
				}
				else
				{
					$this->_Adapter->rollbackTransaction();
				}
			}

			return empty( $this->_Errors );
		}
		catch( \Exception $e )
		{
			// Rollback on error
			if( $this->_Options['use_transaction'] && $this->_Adapter->hasTransaction() )
			{
				$this->_Adapter->rollbackTransaction();
			}

			// Re-enable foreign key checks
			if( $this->_Options['disable_foreign_keys'] )
			{
				try
				{
					$this->enableForeignKeyChecks();
				}
				catch( \Exception $fkException )
				{
					// Ignore
				}
			}

			throw $e;
		}
	}

	/**
	 * Import a single CSV file
	 *
	 * @param string $filePath CSV file path
	 * @param string $tableName Target table name
	 */
	private function importCsvFile( string $filePath, string $tableName ): void
	{
		$handle = fopen( $filePath, 'r' );
		if( !$handle )
		{
			throw new \RuntimeException( "Cannot open CSV file: {$filePath}" );
		}

		try
		{
			// Read header row
			$headers = fgetcsv( $handle, 0, ',', '"', '' );
			if( !$headers )
			{
				throw new \RuntimeException( "CSV file is empty or invalid: {$filePath}" );
			}

			// Prepare table
			if( !$this->prepareTableForImport( $tableName ) )
			{
				return;
			}

			// Read and import data in batches
			$batch = [];
			$batchSize = $this->_Options['batch_size'];

			while( ($row = fgetcsv( $handle, 0, ',', '"', '' )) !== false )
			{
				// Skip comment lines
				if( isset( $row[0] ) && str_starts_with( $row[0], '#' ) )
				{
					continue;
				}

				// Create associative array
				$data = array_combine( $headers, $row );

				if( $data === false )
				{
					continue; // Skip malformed rows
				}

				$batch[] = $data;

				if( count( $batch ) >= $batchSize )
				{
					$this->insertBatch( $tableName, $batch );
					$batch = [];
				}
			}

			// Insert remaining batch
			if( !empty( $batch ) )
			{
				$this->insertBatch( $tableName, $batch );
			}
		}
		finally
		{
			fclose( $handle );
		}
	}

	/**
	 * Import table data
	 *
	 * @param string $table Table name
	 * @param array $rows Data rows
	 */
	private function importTableData( string $table, array $rows ): void
	{
		$batchSize = $this->_Options['batch_size'];
		$batches = array_chunk( $rows, $batchSize );

		foreach( $batches as $batch )
		{
			$this->insertBatch( $table, $batch );
		}
	}

	/**
	 * Insert a batch of rows
	 *
	 * @param string $table Table name
	 * @param array $rows Rows to insert
	 */
	private function insertBatch( string $table, array $rows ): void
	{
		if( empty( $rows ) )
		{
			return;
		}

		// Get column names from first row
		$columns = array_keys( $rows[0] );
		$columnList = '`' . implode( '`, `', $columns ) . '`';

		// Build values
		$values = [];
		foreach( $rows as $row )
		{
			$escapedValues = [];

			foreach( $columns as $column )
			{
				$value = $row[$column] ?? null;

				if( $value === null || $value === 'NULL' )
				{
					$escapedValues[] = 'NULL';
				}
				elseif( is_bool( $value ) )
				{
					$escapedValues[] = $value ? '1' : '0';
				}
				elseif( is_numeric( $value ) )
				{
					$escapedValues[] = $value;
				}
				else
				{
					// Use proper escaping
					$escaped = $this->escapeString( $value );
					$escapedValues[] = "'{$escaped}'";
				}
			}

			$values[] = '(' . implode( ', ', $escapedValues ) . ')';
		}

		// Build and execute INSERT statement
		$sql = "INSERT INTO `{$table}` ({$columnList}) VALUES\n" . implode( ",\n", $values );

		$this->_Adapter->execute( $sql );
		$this->_RowsImported += count( $rows );
	}

	/**
	 * Prepare table for import based on conflict mode
	 *
	 * @param string $table Table name
	 * @return bool Whether to proceed with import
	 */
	private function prepareTableForImport( string $table ): bool
	{
		// Check if table exists
		if( !$this->_Adapter->hasTable( $table ) )
		{
			// Table doesn't exist, can't import
			$this->_Errors[] = "Table {$table} does not exist";
			return false;
		}

		switch( $this->_Options['conflict_mode'] )
		{
			case self::CONFLICT_REPLACE:
				// Clear existing data
				$this->_Adapter->execute( "DELETE FROM `{$table}`" );
				return true;

			case self::CONFLICT_APPEND:
				// Keep existing data, just append
				return true;

			case self::CONFLICT_SKIP:
				// Check if table has data
				$result = $this->_Adapter->fetchRow( "SELECT COUNT(*) as count FROM `{$table}`" );
				if( $result['count'] > 0 )
				{
					// Table has data, skip it
					return false;
				}
				return true;

			default:
				throw new \InvalidArgumentException(
					"Invalid conflict mode: {$this->_Options['conflict_mode']}"
				);
		}
	}

	/**
	 * Split SQL string into individual statements
	 *
	 * @param string $sql SQL string
	 * @return array Array of SQL statements
	 */
	private function splitSqlStatements( string $sql ): array
	{
		// Simple statement splitter - splits on semicolon at end of line
		// This is a basic implementation and may need refinement for complex SQL
		$statements = [];
		$current = '';
		$inString = false;
		$stringChar = '';

		$lines = explode( "\n", $sql );

		foreach( $lines as $line )
		{
			$trimmed = trim( $line );

			// Skip comments
			if( empty( $trimmed ) || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '#' ) )
			{
				continue;
			}

			// Process character by character for proper string handling
			$len = strlen( $line );
			for( $i = 0; $i < $len; $i++ )
			{
				$char = $line[$i];
				$nextChar = ($i < $len - 1) ? $line[$i + 1] : '';

				// Handle string literals
				if( !$inString && ($char === '"' || $char === "'") )
				{
					$inString = true;
					$stringChar = $char;
					$current .= $char;
				}
				elseif( $inString && $char === $stringChar )
				{
					// Check for escaped quotes (two consecutive quotes)
					if( $nextChar === $stringChar )
					{
						// It's an escaped quote - add both quotes and skip next one
						$current .= $char . $nextChar;
						$i++; // Skip the next quote
					}
					else
					{
						// It's the closing quote
						$inString = false;
						$current .= $char;
					}
				}
				elseif( !$inString && $char === ';' )
				{
					// End of statement
					if( !empty( trim( $current ) ) )
					{
						$statements[] = trim( $current );
					}
					$current = '';
				}
				else
				{
					$current .= $char;
				}
			}

			$current .= "\n";
		}

		// Add any remaining statement
		if( !empty( trim( $current ) ) )
		{
			$statements[] = trim( $current );
		}

		return $statements;
	}

	/**
	 * Check if a statement should be processed based on table filters
	 *
	 * @param string $statement SQL statement
	 * @return bool
	 */
	private function shouldProcessStatement( string $statement ): bool
	{
		// Extract table name from various SQL statements
		$patterns = [
			'/^INSERT\s+INTO\s+[`"]?(\w+)[`"]?/i',
			'/^DELETE\s+FROM\s+[`"]?(\w+)[`"]?/i',
			'/^UPDATE\s+[`"]?(\w+)[`"]?/i',
			'/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i',
			'/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i',
			'/^ALTER\s+TABLE\s+[`"]?(\w+)[`"]?/i',
			'/^TRUNCATE\s+(?:TABLE\s+)?[`"]?(\w+)[`"]?/i'
		];

		$tableName = null;
		foreach( $patterns as $pattern )
		{
			if( preg_match( $pattern, $statement, $matches ) )
			{
				$tableName = $matches[1];
				break;
			}
		}

		// If we can't determine the table, process the statement
		// (could be SET, BEGIN, COMMIT, etc.)
		if( $tableName === null )
		{
			return true;
		}

		return $this->shouldProcessTable( $tableName );
	}

	/**
	 * Check if a table should be processed
	 *
	 * @param string $tableName Table name
	 * @return bool
	 */
	private function shouldProcessTable( string $tableName ): bool
	{
		// Check if table is in the include list
		if( $this->_Options['tables'] !== null )
		{
			if( !in_array( $tableName, $this->_Options['tables'] ) )
			{
				return false;
			}
		}

		// Check if table is in the exclude list
		if( !empty( $this->_Options['exclude'] ) )
		{
			if( in_array( $tableName, $this->_Options['exclude'] ) )
			{
				return false;
			}
		}

		// Always skip migration table unless explicitly included
		if( $tableName === $this->_MigrationTable &&
		    !in_array( $tableName, $this->_Options['tables'] ?? [] ) )
		{
			return false;
		}

		return true;
	}

	/**
	 * Filter tables based on options
	 *
	 * @param array $tables List of table names
	 * @return array Filtered list
	 */
	private function filterTables( array $tables ): array
	{
		$filtered = [];

		foreach( $tables as $table )
		{
			if( $this->shouldProcessTable( $table ) )
			{
				$filtered[] = $table;
			}
		}

		return $filtered;
	}

	/**
	 * Estimate number of rows from INSERT statement
	 *
	 * @param string $statement INSERT statement
	 * @return int Estimated row count
	 */
	private function estimateRowsFromInsert( string $statement ): int
	{
		// Count opening parentheses after VALUES
		if( preg_match( '/VALUES\s*(.+)/is', $statement, $matches ) )
		{
			// Count value sets - each starts with (
			return substr_count( $matches[1], '(' );
		}

		return 1;
	}

	/**
	 * Detect format from file extension or content
	 *
	 * @param string $filePath File path
	 * @param string $content File content
	 * @return string Detected format
	 */
	private function detectFormat( string $filePath, string $content ): string
	{
		// Remove .gz extension if present
		$filePath = str_replace( '.gz', '', $filePath );

		// Check file extension
		$extension = strtolower( pathinfo( $filePath, PATHINFO_EXTENSION ) );

		switch( $extension )
		{
			case 'sql':
				return self::FORMAT_SQL;
			case 'json':
				return self::FORMAT_JSON;
			case 'yaml':
			case 'yml':
				return self::FORMAT_YAML;
			case 'csv':
				return self::FORMAT_CSV;
		}

		// Try to detect from content
		$trimmed = trim( $content );

		// Check for JSON
		if( (str_starts_with( $trimmed, '{' ) || str_starts_with( $trimmed, '[' )) &&
		    (str_ends_with( $trimmed, '}' ) || str_ends_with( $trimmed, ']' )) )
		{
			json_decode( $trimmed );
			if( json_last_error() === JSON_ERROR_NONE )
			{
				return self::FORMAT_JSON;
			}
		}

		// Check for YAML
		if( str_contains( $trimmed, "\n" ) &&
		    (str_contains( $trimmed, ':' ) || str_contains( $trimmed, '- ' )) )
		{
			try
			{
				Yaml::parse( $trimmed );
				return self::FORMAT_YAML;
			}
			catch( \Exception $e )
			{
				// Not YAML
			}
		}

		// Default to SQL
		return self::FORMAT_SQL;
	}

	/**
	 * Escape string for SQL
	 *
	 * @param string $value Value to escape
	 * @return string Escaped value (without surrounding quotes)
	 */
	private function escapeString( string $value ): string
	{
		// Try to use the adapter's native quoting mechanism
		if( method_exists( $this->_Adapter, 'getConnection' ) )
		{
			try
			{
				$connection = $this->_Adapter->getConnection();
				if( $connection instanceof \PDO )
				{
					// PDO::quote adds quotes around the string, but our callers
					// add quotes themselves, so we need to strip them
					$quoted = $connection->quote( $value );
					// Remove the surrounding quotes that PDO adds
					if( strlen( $quoted ) >= 2 )
					{
						// Strip first and last character (the quotes)
						return substr( $quoted, 1, -1 );
					}
					return $value;
				}
			}
			catch( \Exception $e )
			{
				// Fall through to manual escaping if adapter method fails
			}
		}

		// Fallback to manual escaping for adapters without PDO access
		// or if the PDO method fails
		return str_replace(
			["\\", "'", '"', "\n", "\r", "\t", "\x00", "\x1a"],
			["\\\\", "''", '\"', "\\n", "\\r", "\\t", "\\0", "\\Z"],
			$value
		);
	}

	/**
	 * Disable foreign key checks
	 */
	private function disableForeignKeyChecks(): void
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				$this->_Adapter->execute( 'SET FOREIGN_KEY_CHECKS = 0' );
				break;
			case 'sqlite':
				$this->_Adapter->execute( 'PRAGMA foreign_keys = OFF' );
				break;
			case 'pgsql':
				// PostgreSQL doesn't have a global foreign key disable
				// Would need to defer constraints within transaction
				$this->_Adapter->execute( 'SET CONSTRAINTS ALL DEFERRED' );
				break;
		}
	}

	/**
	 * Enable foreign key checks
	 */
	private function enableForeignKeyChecks(): void
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				$this->_Adapter->execute( 'SET FOREIGN_KEY_CHECKS = 1' );
				break;
			case 'sqlite':
				$this->_Adapter->execute( 'PRAGMA foreign_keys = ON' );
				break;
			case 'pgsql':
				// Constraints will be checked at transaction commit
				$this->_Adapter->execute( 'SET CONSTRAINTS ALL IMMEDIATE' );
				break;
		}
	}

	/**
	 * Get import errors
	 *
	 * @return array
	 */
	public function getErrors(): array
	{
		return $this->_Errors;
	}

	/**
	 * Get import statistics
	 *
	 * @return array
	 */
	public function getStatistics(): array
	{
		return [
			'rows_imported' => $this->_RowsImported,
			'tables_imported' => $this->_TablesImported,
			'errors' => count( $this->_Errors )
		];
	}

	/**
	 * Clear all data from database (dangerous!)
	 *
	 * @param bool $includeMigrationTable Whether to clear migration table too
	 * @return bool Success status
	 */
	public function clearAllData( bool $includeMigrationTable = false ): bool
	{
		try
		{
			// Disable foreign key checks
			$this->disableForeignKeyChecks();

			// Get all tables
			$tables = $this->getAllTables();

			foreach( $tables as $table )
			{
				// Skip migration table unless specified
				if( !$includeMigrationTable && $table === $this->_MigrationTable )
				{
					continue;
				}

				// Skip if not in table filter
				if( !$this->shouldProcessTable( $table ) )
				{
					continue;
				}

				$this->_Adapter->execute( "DELETE FROM `{$table}`" );
			}

			// Re-enable foreign key checks
			$this->enableForeignKeyChecks();

			return true;
		}
		catch( \Exception $e )
		{
			$this->_Errors[] = "Error clearing data: " . $e->getMessage();

			// Try to re-enable foreign key checks
			try
			{
				$this->enableForeignKeyChecks();
			}
			catch( \Exception $fkException )
			{
				// Ignore
			}

			return false;
		}
	}

	/**
	 * Get table row counts for all tables in database
	 *
	 * @return array Table => row count mapping
	 */
	public function getTableRowCounts(): array
	{
		$tables = $this->getAllTables();
		$result = [];

		foreach( $tables as $table )
		{
			try
			{
				$row = $this->_Adapter->fetchRow( "SELECT COUNT(*) as count FROM `{$table}`" );
				$result[$table] = (int)$row['count'];
			}
			catch( \Exception $e )
			{
				$result[$table] = 0;
			}
		}

		return $result;
	}

	/**
	 * Get list of all tables in database
	 *
	 * @return array
	 */
	private function getAllTables(): array
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				$sql = "SELECT TABLE_NAME FROM information_schema.TABLES
						WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
						ORDER BY TABLE_NAME";
				$rows = $this->_Adapter->fetchAll( $sql, [$this->_Adapter->getOption( 'name' )] );
				return array_column( $rows, 'TABLE_NAME' );

			case 'pgsql':
				$sql = "SELECT tablename FROM pg_catalog.pg_tables
						WHERE schemaname = 'public'
						ORDER BY tablename";
				$rows = $this->_Adapter->fetchAll( $sql );
				return array_column( $rows, 'tablename' );

			case 'sqlite':
				$sql = "SELECT name FROM sqlite_master
						WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
						ORDER BY name";
				$rows = $this->_Adapter->fetchAll( $sql );
				return array_column( $rows, 'name' );

			default:
				throw new \RuntimeException( "Unsupported adapter type: {$this->_AdapterType}" );
		}
	}

	/**
	 * Verify import by checking row counts
	 *
	 * @param array $expectedCounts Array of table => expected row count
	 * @return array Verification results
	 */
	public function verifyImport( array $expectedCounts ): array
	{
		$results = [];

		foreach( $expectedCounts as $table => $expectedCount )
		{
			$actualCount = 0;

			try
			{
				if( $this->_Adapter->hasTable( $table ) )
				{
					$result = $this->_Adapter->fetchRow( "SELECT COUNT(*) as count FROM `{$table}`" );
					$actualCount = (int)$result['count'];
				}

				$results[$table] = [
					'expected' => $expectedCount,
					'actual' => $actualCount,
					'match' => $actualCount === $expectedCount
				];
			}
			catch( \Exception $e )
			{
				$results[$table] = [
					'expected' => $expectedCount,
					'actual' => 0,
					'match' => false,
					'error' => $e->getMessage()
				];
			}
		}

		return $results;
	}

	/**
	 * Disconnect from database
	 */
	public function disconnect(): void
	{
		$this->_Adapter->disconnect();
	}

	/**
	 * Destructor - ensure adapter is disconnected
	 */
	public function __destruct()
	{
		if( isset( $this->_Adapter ) )
		{
			try
			{
				$this->_Adapter->disconnect();
			}
			catch( \Exception $e )
			{
				// Silently handle disconnect errors in destructor
			}
		}
	}
}