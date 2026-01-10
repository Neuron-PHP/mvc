<?php

namespace Neuron\Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;
use Neuron\Log\Log;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports database data in various formats
 * Supports SQL, JSON, CSV, and YAML output formats
 */
class DataExporter
{
	private AdapterInterface $_Adapter;
	private string $_MigrationTable;
	private string $_AdapterType;
	private IFileSystem $fs;
	private array $_Options;

	// Output format constants
	const FORMAT_SQL = 'sql';
	const FORMAT_JSON = 'json';
	const FORMAT_CSV = 'csv';
	const FORMAT_YAML = 'yaml';

	/**
	 * @param Config $PhinxConfig Phinx configuration
	 * @param string $Environment Environment name
	 * @param string $MigrationTable Migration tracking table name
	 * @param array $Options Export options
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
			'tables' => null, // null = all tables
			'exclude' => [],
			'limit' => null, // null = no limit
			'where' => [], // table => where condition
			'include_schema' => false,
			'drop_tables' => false,
			'use_transaction' => true,
			'compress' => false
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
	 * Export data to string
	 *
	 * @return string Exported data
	 */
	public function export(): string
	{
		$tables = $this->getTableList();

		switch( $this->_Options['format'] )
		{
			case self::FORMAT_SQL:
				return $this->exportToSql( $tables );
			case self::FORMAT_JSON:
				return $this->exportToJson( $tables );
			case self::FORMAT_CSV:
				return $this->exportToCsv( $tables );
			case self::FORMAT_YAML:
				return $this->exportToYaml( $tables );
			default:
				throw new \InvalidArgumentException( "Unsupported format: {$this->_Options['format']}" );
		}
	}

	/**
	 * Export data to file
	 *
	 * @param string $FilePath Path to output file
	 * @return string|false Actual file path written or false on failure
	 */
	public function exportToFile( string $FilePath ): string|false
	{
		// Apply .gz extension if compression is enabled and path doesn't already end with .gz
		if( $this->_Options['compress'] && !str_ends_with( strtolower( $FilePath ), '.gz' ) )
		{
			$actualPath = $FilePath . '.gz';
		}
		else
		{
			$actualPath = $FilePath;
		}

		// For large datasets, use streaming export
		if( $this->shouldUseStreaming() )
		{
			$result = $this->streamExportToFile( $actualPath );
			return $result ? $actualPath : false;
		}

		$data = $this->export();

		// Create directory if needed
		$directory = dirname( $actualPath );
		if( !$this->fs->isDir( $directory ) )
		{
			$this->fs->mkdir( $directory, 0755, true );
		}

		// Apply compression if requested
		if( $this->_Options['compress'] )
		{
			$data = gzencode( $data );
			if( $data === false )
			{
				throw new \RuntimeException( "Failed to compress data for file: {$actualPath}" );
			}
		}

		$result = $this->fs->writeFile( $actualPath, $data );

		return $result !== false ? $actualPath : false;
	}

	/**
	 * Stream export to file for large datasets
	 *
	 * @param string $FilePath Path to output file (may have .gz extension if compressed)
	 * @return bool Success status
	 */
	private function streamExportToFile( string $FilePath ): bool
	{
		$directory = dirname( $FilePath );
		if( !$this->fs->isDir( $directory ) )
		{
			$this->fs->mkdir( $directory, 0755, true );
		}

		// Open file handle - use gzopen for compression
		if( $this->_Options['compress'] )
		{
			$handle = gzopen( $FilePath, 'w9' ); // w9 = write with maximum compression
		}
		else
		{
			$handle = fopen( $FilePath, 'w' );
		}

		if( !$handle )
		{
			return false;
		}

		try
		{
			$tables = $this->getTableList();

			// Write header based on format
			$this->writeStreamHeader( $handle );

			// Export each table
			foreach( $tables as $index => $table )
			{
				if( $index > 0 )
				{
					$this->writeStreamTableSeparator( $handle );
				}

				$this->streamExportTable( $handle, $table );
			}

			// Write footer
			$this->writeStreamFooter( $handle );

			// Close handle - use gzclose for compressed files
			if( $this->_Options['compress'] )
			{
				gzclose( $handle );
			}
			else
			{
				fclose( $handle );
			}
			return true;
		}
		catch( \Exception $e )
		{
			// Close handle based on compression
			if( $this->_Options['compress'] )
			{
				gzclose( $handle );
			}
			else
			{
				fclose( $handle );
			}
			throw $e;
		}
	}

	/**
	 * Write data to a file handle (handles both compressed and uncompressed)
	 *
	 * @param resource $handle File handle (regular or gzip)
	 * @param string $data Data to write
	 * @return int|false Number of bytes written or false on error
	 */
	private function writeToHandle( $handle, string $data )
	{
		if( $this->_Options['compress'] )
		{
			return gzwrite( $handle, $data );
		}
		else
		{
			return fwrite( $handle, $data );
		}
	}

	/**
	 * Write CSV row to a file handle (handles both compressed and uncompressed)
	 *
	 * @param resource $handle File handle (regular or gzip)
	 * @param array $row Row data to write as CSV
	 * @return int|false Number of bytes written or false on error
	 */
	private function writeCsvToHandle( $handle, array $row )
	{
		// Always format as CSV string and use writeToHandle for consistency
		// This ensures compression and other output-layer logic are honored
		$csvLine = $this->formatCsvLine( $row );
		return $this->writeToHandle( $handle, $csvLine );
	}

	/**
	 * Format array as CSV line
	 *
	 * @param array $row Row data
	 * @return string CSV formatted line
	 */
	private function formatCsvLine( array $row ): string
	{
		$handle = fopen( 'php://temp', 'r+' );
		// PHP 8.4 requires the escape parameter to be explicitly set
		fputcsv( $handle, $row, ',', '"', '' );
		rewind( $handle );
		$line = stream_get_contents( $handle );
		fclose( $handle );
		return $line;
	}

	/**
	 * Get list of tables to export
	 *
	 * @return array
	 */
	public function getTableList(): array
	{
		// Get all tables
		$allTables = $this->getTables();

		// Filter based on options
		if( !empty( $this->_Options['tables'] ) )
		{
			// Only include specified tables
			$tables = array_intersect( $allTables, $this->_Options['tables'] );
		}
		else
		{
			$tables = $allTables;
		}

		// Exclude specified tables
		if( !empty( $this->_Options['exclude'] ) )
		{
			$tables = array_diff( $tables, $this->_Options['exclude'] );
		}

		// Always exclude migration table unless explicitly included
		if( !in_array( $this->_MigrationTable, $this->_Options['tables'] ?? [] ) )
		{
			$tables = array_diff( $tables, [$this->_MigrationTable] );
		}

		return array_values( $tables );
	}

	/**
	 * Export data to SQL format
	 *
	 * @param array $tables List of tables
	 * @return string SQL dump
	 */
	private function exportToSql( array $tables ): string
	{
		$sql = [];

		// Header
		$sql[] = "-- Neuron PHP Database Data Dump";
		$sql[] = "-- Generated: " . date( 'Y-m-d H:i:s' );
		$sql[] = "-- Database Type: " . $this->_AdapterType;
		$sql[] = "";

		// Disable foreign key checks
		if( $this->_AdapterType === 'mysql' )
		{
			$sql[] = "SET FOREIGN_KEY_CHECKS = 0;";
		}
		elseif( $this->_AdapterType === 'sqlite' )
		{
			$sql[] = "PRAGMA foreign_keys = OFF;";
		}
		$sql[] = "";

		// Start transaction if requested
		if( $this->_Options['use_transaction'] )
		{
			$sql[] = "BEGIN;";
			$sql[] = "";
		}

		// Export each table
		foreach( $tables as $table )
		{
			$sql[] = "-- Table: {$table}";

			// Drop table if requested
			if( $this->_Options['drop_tables'] )
			{
				$quoted = $this->quoteIdentifier( $table );
				$sql[] = "DROP TABLE IF EXISTS {$quoted};";
			}

			// Include schema if requested
			if( $this->_Options['include_schema'] )
			{
				$sql[] = $this->getTableCreateStatement( $table );
			}

			// Clear existing data
			$quoted = $this->quoteIdentifier( $table );
			$sql[] = "DELETE FROM {$quoted};";

			// Export data
			$data = $this->getTableData( $table );
			if( !empty( $data ) )
			{
				$sql[] = $this->buildInsertStatements( $table, $data );
			}

			$sql[] = "";
		}

		// Commit transaction
		if( $this->_Options['use_transaction'] )
		{
			$sql[] = "COMMIT;";
		}

		// Re-enable foreign key checks
		if( $this->_AdapterType === 'mysql' )
		{
			$sql[] = "SET FOREIGN_KEY_CHECKS = 1;";
		}
		elseif( $this->_AdapterType === 'sqlite' )
		{
			$sql[] = "PRAGMA foreign_keys = ON;";
		}

		return implode( "\n", $sql );
	}

	/**
	 * Export data to JSON format
	 *
	 * @param array $tables List of tables
	 * @return string JSON data
	 */
	private function exportToJson( array $tables ): string
	{
		$data = [
			'metadata' => [
				'exported_at' => date( 'Y-m-d H:i:s' ),
				'database_type' => $this->_AdapterType,
				'tables_count' => count( $tables )
			],
			'data' => []
		];

		foreach( $tables as $table )
		{
			$tableData = $this->getTableData( $table );

			$data['data'][$table] = [
				'rows_count' => count( $tableData ),
				'rows' => $tableData
			];
		}

		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Export data to CSV format
	 * Note: CSV format exports each table to a separate file
	 *
	 * @param array $tables List of tables
	 * @return string CSV metadata (actual data needs file output)
	 */
	private function exportToCsv( array $tables ): string
	{
		// For CSV, we return metadata about what would be exported
		// Actual CSV export should use exportCsvToDirectory method
		$metadata = [];

		foreach( $tables as $table )
		{
			$rowCount = $this->getTableRowCount( $table );
			$metadata[] = "Table: {$table} - {$rowCount} rows";
		}

		return "CSV Export Metadata\n" .
		       "===================\n" .
		       implode( "\n", $metadata ) . "\n\n" .
		       "Note: Use exportCsvToDirectory() method for actual CSV export";
	}

	/**
	 * Export data to YAML format
	 *
	 * @param array $tables List of tables
	 * @return string YAML data
	 */
	private function exportToYaml( array $tables ): string
	{
		$data = [
			'metadata' => [
				'exported_at' => date( 'Y-m-d H:i:s' ),
				'database_type' => $this->_AdapterType,
				'tables_count' => count( $tables )
			],
			'data' => []
		];

		foreach( $tables as $table )
		{
			$tableData = $this->getTableData( $table );
			$data['data'][$table] = $tableData;
		}

		return Yaml::dump( $data, 4, 2 );
	}

	/**
	 * Export all tables to CSV files in a directory
	 *
	 * @param string $DirectoryPath Directory to export CSV files to
	 * @return array List of exported files
	 */
	public function exportCsvToDirectory( string $DirectoryPath ): array
	{
		$tables = $this->getTableList();
		$exportedFiles = [];

		// Create directory if needed
		if( !$this->fs->isDir( $DirectoryPath ) )
		{
			$this->fs->mkdir( $DirectoryPath, 0755, true );
		}

		foreach( $tables as $table )
		{
			$filePath = $DirectoryPath . '/' . $table . '.csv';

			if( $this->exportTableToCsv( $table, $filePath ) )
			{
				$exportedFiles[] = $filePath;
			}
		}

		// Write metadata file
		$metadataPath = $DirectoryPath . '/export_metadata.json';
		$metadata = [
			'exported_at' => date( 'Y-m-d H:i:s' ),
			'database_type' => $this->_AdapterType,
			'tables' => array_map( 'basename', $exportedFiles )
		];

		$this->fs->writeFile( $metadataPath, json_encode( $metadata, JSON_PRETTY_PRINT ) );
		$exportedFiles[] = $metadataPath;

		return $exportedFiles;
	}

	/**
	 * Export a single table to CSV file
	 *
	 * @param string $table Table name
	 * @param string $filePath Output file path
	 * @return bool Success status
	 */
	private function exportTableToCsv( string $table, string $filePath ): bool
	{
		try
		{
			// Get table data
			$data = $this->getTableData( $table );

			// Build CSV content
			$csvContent = '';

			if( !empty( $data ) )
			{
				// Add header row
				$csvContent .= $this->formatCsvLine( array_keys( $data[0] ) );

				// Add data rows
				foreach( $data as $row )
				{
					$csvContent .= $this->formatCsvLine( $row );
				}
			}

			// Write to file using filesystem abstraction
			$result = $this->fs->writeFile( $filePath, $csvContent );
			return $result !== false;
		}
		catch( \Exception $e )
		{
			throw $e;
		}
	}

	/**
	 * Get data from a table
	 *
	 * @param string $table Table name
	 * @return array Table data
	 */
	private function getTableData( string $table ): array
	{
		// For proper SQL injection prevention, we need to use prepared statements
		// Since Phinx adapters don't support parameterized queries natively,
		// we access the underlying PDO connection when available

		// Normalize WHERE clause - treat empty/whitespace as no WHERE
		$whereClause = trim( $this->_Options['where'][$table] ?? '' );

		if( $whereClause !== '' )
		{
			// First validate the WHERE clause structure
			if( !SqlWhereValidator::isValid( $whereClause ) )
			{
				throw new \InvalidArgumentException(
					"Potentially dangerous WHERE clause detected for table '{$table}'. " .
					"WHERE clauses must not contain SQL commands, comments, or subqueries."
				);
			}

			// Try to use PDO for prepared statements if available
			if( method_exists( $this->_Adapter, 'getConnection' ) )
			{
				$pdo = $this->_Adapter->getConnection();
				return $this->getTableDataWithPDO( $pdo, $table, $whereClause );
			}
			else
			{
				// Fallback: Use validation + direct SQL (less secure)
				// Log a warning that prepared statements aren't available
				Log::warning( "PDO not available for prepared statements in DataExporter" );

				$quoted = $this->quoteIdentifier( $table );
				$sql = "SELECT * FROM {$quoted} WHERE " . $whereClause;

				if( $this->_Options['limit'] !== null )
				{
					$sql .= " LIMIT " . (int)$this->_Options['limit'];
				}

				return $this->_Adapter->fetchAll( $sql );
			}
		}
		else
		{
			// No WHERE clause - safe to use direct SQL
			$quoted = $this->quoteIdentifier( $table );
			$sql = "SELECT * FROM {$quoted}";

			if( $this->_Options['limit'] !== null )
			{
				$sql .= " LIMIT " . (int)$this->_Options['limit'];
			}

			return $this->_Adapter->fetchAll( $sql );
		}
	}

	/**
	 * Get table data using PDO prepared statements
	 *
	 * @param \PDO $pdo PDO connection
	 * @param string $table Table name
	 * @param string $whereClause WHERE clause
	 * @return array Table data
	 */
	private function getTableDataWithPDO( \PDO $pdo, string $table, string $whereClause ): array
	{
		// Parse the WHERE clause to extract column-value pairs
		// This is a simplified parser - production code should use a proper SQL parser
		$parsed = $this->parseSimpleWhereClause( $whereClause );

		$quoted = $this->quoteIdentifier( $table );
		$sql = "SELECT * FROM {$quoted} WHERE " . $parsed['sql'];

		if( $this->_Options['limit'] !== null )
		{
			$sql .= " LIMIT " . (int)$this->_Options['limit'];
		}

		$stmt = $pdo->prepare( $sql );
		$stmt->execute( $parsed['bindings'] );

		return $stmt->fetchAll( \PDO::FETCH_ASSOC );
	}

	/**
	 * Parse a simple WHERE clause for parameterization
	 *
	 * Converts "status = 'active' AND type = 'user'" to parameterized SQL
	 * Note: This is a basic implementation - production should use full SQL parser
	 *
	 * @param string $whereClause WHERE clause to parse
	 * @return array Array with 'sql' and 'bindings' keys
	 */
	private function parseSimpleWhereClause( string $whereClause ): array
	{
		$sql = '';
		$bindings = [];

		// Pattern to match column operator value pairs
		// Note: Order matters - check multi-char operators (<=, >=, !=, <>) before single-char (<, >, =)
		// This pattern properly handles SQL-escaped quotes (e.g., 'O''Brien' or "He said ""hi""")
		// Group 1: column name
		// Group 2: operator
		// Group 3: quoted value with single quotes (including escaped '')
		// Group 4: quoted value with double quotes (including escaped "")
		// Group 5: unquoted value
		$conditionPattern = '/(\w+)\s*(<=|>=|!=|<>|=|<|>|LIKE|NOT LIKE)\s*(?:\'((?:\'\'|[^\'])*)\'|"((?:""|[^"])*)"|(\S+))/i';

		// First, split by AND/OR while preserving the operators
		// This pattern captures conditions and the operators between them
		$parts = preg_split( '/\s+(AND|OR)\s+/i', $whereClause, -1, PREG_SPLIT_DELIM_CAPTURE );

		if( empty( $parts ) )
		{
			throw new \InvalidArgumentException(
				"Cannot parse WHERE clause for parameterization: {$whereClause}. " .
				"Consider using the ORM QueryBuilder for complex queries."
			);
		}

		$parameterizedParts = [];

		for( $i = 0; $i < count( $parts ); $i++ )
		{
			$part = trim( $parts[$i] );

			// Check if this is an operator (AND/OR)
			if( in_array( strtoupper( $part ), ['AND', 'OR'] ) )
			{
				$parameterizedParts[] = strtoupper( $part );
				continue;
			}

			// This should be a condition
			// Check for parentheses which indicate complex expressions we don't support
			if( strpos( $part, '(' ) !== false || strpos( $part, ')' ) !== false )
			{
				throw new \InvalidArgumentException(
					"Parentheses are not supported in WHERE conditions: {$part}. " .
					"Consider using the ORM QueryBuilder for complex queries."
				);
			}

			// Check for IS NULL / IS NOT NULL patterns first (these don't take a value)
			$nullPattern = '/(\w+)\s+(IS\s+NOT\s+NULL|IS\s+NULL)/i';
			if( preg_match( $nullPattern, $part, $match ) )
			{
				$column = $match[1];
				$operator = strtoupper( $match[2] );

				// Use quoted column but no placeholder (no value to bind)
				$quotedColumn = $this->quoteIdentifier( $column );
				$parameterizedParts[] = "{$quotedColumn} {$operator}";
				// No binding needed for NULL checks
			}
			elseif( preg_match( $conditionPattern, $part, $match ) )
			{
				$column = $match[1];
				$operator = strtoupper( $match[2] );

				// Extract value from the appropriate capture group
				// Group 3: single-quoted value (may contain escaped '')
				// Group 4: double-quoted value (may contain escaped "")
				// Group 5: unquoted value
				// Check unquoted first since it cannot be empty (uses \S+ pattern)
				// Use !== '' instead of !empty() to handle values like '0' correctly
				// Use isset() to check if group participated in match (PHP may not set non-participating groups)
				if( isset( $match[5] ) && $match[5] !== '' )
				{
					// Unquoted value (cannot be empty due to \S+ pattern)
					$value = $match[5];
				}
				elseif( isset( $match[3] ) && $match[3] !== '' )
				{
					// Single-quoted non-empty value - unescape doubled single quotes
					$value = str_replace( "''", "'", $match[3] );
				}
				elseif( isset( $match[4] ) && $match[4] !== '' )
				{
					// Double-quoted non-empty value - unescape doubled double quotes
					$value = str_replace( '""', '"', $match[4] );
				}
				else
				{
					// All groups are empty - must be an empty quoted string
					// Could be either '' or "" - doesn't matter since result is empty
					$value = '';
				}

				// Use placeholder for binding
				$quotedColumn = $this->quoteIdentifier( $column );
				$parameterizedParts[] = "{$quotedColumn} {$operator} ?";
				$bindings[] = $value;
			}
			else
			{
				// If we can't parse this part, it might be a complex expression
				throw new \InvalidArgumentException(
					"Cannot parse WHERE condition: {$part}. " .
					"Consider using the ORM QueryBuilder for complex queries."
				);
			}
		}

		// Join all parts to create the final SQL
		$sql = implode( ' ', $parameterizedParts );

		return [
			'sql' => $sql,
			'bindings' => $bindings
		];
	}

	/**
	 * Get row count for a table
	 *
	 * @param string $table Table name
	 * @return int Row count
	 */
	private function getTableRowCount( string $table ): int
	{
		// Normalize WHERE clause - treat empty/whitespace as no WHERE
		$whereClause = trim( $this->_Options['where'][$table] ?? '' );

		if( $whereClause !== '' )
		{
			// Validate WHERE clause for SQL injection attempts
			if( !SqlWhereValidator::isValid( $whereClause ) )
			{
				throw new \InvalidArgumentException(
					"Potentially dangerous WHERE clause detected for table '{$table}'. " .
					"WHERE clauses must not contain SQL commands, comments, or subqueries."
				);
			}

			// Try to use PDO for prepared statements if available
			if( method_exists( $this->_Adapter, 'getConnection' ) )
			{
				$pdo = $this->_Adapter->getConnection();
				$parsed = $this->parseSimpleWhereClause( $whereClause );

				$quoted = $this->quoteIdentifier( $table );
				$sql = "SELECT COUNT(*) as count FROM {$quoted} WHERE " . $parsed['sql'];
				$stmt = $pdo->prepare( $sql );
				$stmt->execute( $parsed['bindings'] );

				$result = $stmt->fetch( \PDO::FETCH_ASSOC );
				// Check if query failed or table doesn't exist
				if( !$result || !isset( $result['count'] ) )
				{
					Log::warning( "Could not fetch row count for table '{$table}' - table may have been dropped" );
					return 0;
				}
				return (int)$result['count'];
			}
			else
			{
				// Fallback: Use validation + direct SQL (less secure)
				Log::warning( "PDO not available for prepared statements in DataExporter::getTableRowCount" );

				$quoted = $this->quoteIdentifier( $table );
				$sql = "SELECT COUNT(*) as count FROM {$quoted} WHERE " . $whereClause;
				$result = $this->_Adapter->fetchRow( $sql );
				// Check if query failed or table doesn't exist
				if( !$result || !isset( $result['count'] ) )
				{
					Log::warning( "Could not fetch row count for table '{$table}' - table may have been dropped" );
					return 0;
				}
				return (int)$result['count'];
			}
		}
		else
		{
			// No WHERE clause - safe to use direct SQL
			$quoted = $this->quoteIdentifier( $table );
			$sql = "SELECT COUNT(*) as count FROM {$quoted}";
			$result = $this->_Adapter->fetchRow( $sql );
			// Check if query failed or table doesn't exist
			if( !$result || !isset( $result['count'] ) )
			{
				Log::warning( "Could not fetch row count for table '{$table}' - table may have been dropped" );
				return 0;
			}
			return (int)$result['count'];
		}
	}

	/**
	 * Build INSERT statements for table data
	 *
	 * @param string $table Table name
	 * @param array $data Table data
	 * @return string INSERT statements
	 */
	private function buildInsertStatements( string $table, array $data ): string
	{
		if( empty( $data ) )
		{
			return "";
		}

		$statements = [];
		$columns = array_keys( $data[0] );

		// Quote column identifiers
		$quotedColumns = array_map( [$this, 'quoteIdentifier'], $columns );
		$columnList = implode( ', ', $quotedColumns );

		// Build INSERT statements in batches
		$batchSize = 100;
		$batches = array_chunk( $data, $batchSize );

		foreach( $batches as $batch )
		{
			$values = [];

			foreach( $batch as $row )
			{
				$escapedValues = [];

				foreach( $row as $value )
				{
					if( $value === null )
					{
						$escapedValues[] = 'NULL';
					}
					elseif( is_bool( $value ) )
					{
						$escapedValues[] = $this->formatBooleanLiteral( $value );
					}
					elseif( is_numeric( $value ) && !$this->hasLeadingZeros( $value ) )
					{
						// Only treat as number if it doesn't have leading zeros
						$escapedValues[] = $value;
					}
					else
					{
						// Escape and quote string values (including numeric strings with leading zeros)
						$escapedValue = $this->escapeString( $value );
						$escapedValues[] = "'{$escapedValue}'";
					}
				}

				$values[] = '(' . implode( ', ', $escapedValues ) . ')';
			}

			$quotedTable = $this->quoteIdentifier( $table );
			$statements[] = "INSERT INTO {$quotedTable} ({$columnList}) VALUES\n" .
			                implode( ",\n", $values ) . ";";
		}

		return implode( "\n", $statements );
	}

	/**
	 * Format boolean value as adapter-appropriate SQL literal
	 *
	 * PostgreSQL requires TRUE/FALSE literals for boolean columns.
	 * Other databases accept 1/0 for booleans.
	 *
	 * @param bool $value Boolean value to format
	 * @return string SQL literal representation
	 */
	private function formatBooleanLiteral( bool $value ): string
	{
		// Keep INSERTs portable across adapters
		return match( $this->_AdapterType )
		{
			'pgsql', 'postgres' => $value ? 'TRUE' : 'FALSE',
			default => $value ? '1' : '0',
		};
	}

	/**
	 * Escape string for SQL
	 *
	 * @param string $value Value to escape
	 * @return string Escaped value
	 * @throws \RuntimeException if safe escaping is not available
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
				// Log the error but continue to check other methods
			}
		}

		// Adapter-specific escaping for known safe implementations
		if( method_exists( $this->_Adapter, 'escapeString' ) )
		{
			// Some adapters may provide their own escaping method
			return $this->_Adapter->escapeString( $value );
		}

		// For SQLite, we can use a safer fallback since it uses standard SQL escaping
		if( $this->_AdapterType === 'sqlite' )
		{
			// SQLite uses standard SQL escaping - double single quotes
			return str_replace( "'", "''", $value );
		}

		// No safe escaping method available
		// Manual string replacement is NOT safe for production use due to:
		// 1. Multi-byte character encoding issues
		// 2. Database-specific escaping requirements
		// 3. Potential SQL injection vulnerabilities
		throw new \RuntimeException(
			"Cannot safely escape SQL values without PDO connection. " .
			"This adapter does not support safe string escaping. " .
			"Consider using prepared statements or upgrading to an adapter with PDO support."
		);
	}

	/**
	 * Check if a value has leading zeros that would be lost if treated as numeric
	 *
	 * @param mixed $value Value to check
	 * @return bool True if the value has leading zeros that need preservation
	 */
	private function hasLeadingZeros( $value ): bool
	{
		// Only check string values
		if( !is_string( $value ) )
		{
			return false;
		}

		// Check if it starts with '0' and has more characters
		if( strlen( $value ) > 1 && $value[0] === '0' )
		{
			// But allow decimal numbers like "0.5"
			if( $value[1] === '.' )
			{
				return false;
			}
			// Has leading zero(s) that would be lost
			return true;
		}

		return false;
	}

	/**
	 * Quote a database identifier (table or column name)
	 *
	 * @param string $identifier The identifier to quote
	 * @return string The properly quoted identifier for the current adapter
	 */
	private function quoteIdentifier( string $identifier ): string
	{
		// Handle different database adapters
		switch( $this->_AdapterType )
		{
			case 'mysql':
				// MySQL uses backticks
				return '`' . str_replace( '`', '``', $identifier ) . '`';

			case 'pgsql':
			case 'postgres':
				// PostgreSQL uses double quotes
				return '"' . str_replace( '"', '""', $identifier ) . '"';

			case 'sqlite':
				// SQLite can use double quotes, square brackets, or backticks
				// We'll use double quotes for consistency with standard SQL
				return '"' . str_replace( '"', '""', $identifier ) . '"';

			case 'sqlsrv':
			case 'mssql':
				// SQL Server uses square brackets
				return '[' . str_replace( ']', ']]', $identifier ) . ']';

			default:
				// Default to ANSI SQL double quotes
				return '"' . str_replace( '"', '""', $identifier ) . '"';
		}
	}

	/**
	 * Get CREATE TABLE statement for a table
	 *
	 * @param string $table Table name
	 * @return string CREATE TABLE statement
	 */
	private function getTableCreateStatement( string $table ): string
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				$quoted = $this->quoteIdentifier( $table );
				$result = $this->_Adapter->fetchRow( "SHOW CREATE TABLE {$quoted}" );
				// Check if query failed or table doesn't exist
				if( !$result || !isset( $result['Create Table'] ) )
				{
					Log::warning( "Could not fetch CREATE TABLE statement for table '{$table}' - table may have been dropped" );
					return "-- CREATE TABLE statement not available for table '{$table}' (table not found or query failed)";
				}
				return $result['Create Table'] . ";";

			case 'sqlite':
				// Try to use PDO for parameter binding - Phinx adapters don't support it
				if( method_exists( $this->_Adapter, 'getConnection' ) )
				{
					try
					{
						$connection = $this->_Adapter->getConnection();
						if( $connection instanceof \PDO )
						{
							$sql = "SELECT sql FROM sqlite_master WHERE type='table' AND name=?";
							$stmt = $connection->prepare( $sql );
							if( $stmt !== false )
							{
								$stmt->execute( [$table] );
								$result = $stmt->fetch( \PDO::FETCH_ASSOC );
								if( $result && isset( $result['sql'] ) )
								{
									return $result['sql'] . ";";
								}
								else
								{
									Log::warning( "Could not fetch CREATE TABLE statement for table '{$table}' - table may have been dropped" );
									return "-- CREATE TABLE statement not available for table '{$table}' (table not found or query failed)";
								}
							}
						}
					}
					catch( \Exception $e )
					{
						// Fall through to fallback
					}
				}

				// Fallback: Use escaped string
				$escapedTable = str_replace( "'", "''", $table );
				$sql = "SELECT sql FROM sqlite_master WHERE type='table' AND name='{$escapedTable}'";
				$result = $this->_Adapter->fetchRow( $sql );
				// Check if query failed or table doesn't exist
				if( !$result || !isset( $result['sql'] ) )
				{
					Log::warning( "Could not fetch CREATE TABLE statement for table '{$table}' - table may have been dropped" );
					return "-- CREATE TABLE statement not available for table '{$table}' (table not found or query failed)";
				}
				return $result['sql'] . ";";

			case 'pgsql':
				// PostgreSQL doesn't have a simple SHOW CREATE TABLE
				// Would need to build it from information_schema
				return "-- CREATE TABLE statement not available for PostgreSQL in this version";

			default:
				return "-- CREATE TABLE statement not available for {$this->_AdapterType}";
		}
	}

	/**
	 * Check if streaming export should be used
	 *
	 * @return bool
	 */
	private function shouldUseStreaming(): bool
	{
		// Streaming bypasses IFileSystem (uses fopen/gzopen directly)
		// Only use streaming with RealFileSystem to prevent:
		// 1. Testability issues (mocks/custom filesystems won't work)
		// 2. Unexpected writes to real disk when using mock filesystem
		// 3. Behavior divergence between streaming and non-streaming modes
		if( !$this->fs instanceof RealFileSystem )
		{
			return false;
		}

		// Use streaming for SQL and CSV formats with large datasets
		if( !in_array( $this->_Options['format'], [self::FORMAT_SQL, self::FORMAT_CSV] ) )
		{
			return false;
		}

		// Check total row count
		$tables = $this->getTableList();
		$totalRows = 0;

		foreach( $tables as $table )
		{
			$totalRows += $this->getTableRowCount( $table );

			// Use streaming if more than 10,000 rows
			if( $totalRows > 10000 )
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Stream export a table to file handle
	 *
	 * @param resource $handle File handle
	 * @param string $table Table name
	 */
	private function streamExportTable( $handle, string $table ): void
	{
		// Implementation depends on format
		switch( $this->_Options['format'] )
		{
			case self::FORMAT_SQL:
				$this->streamSqlTable( $handle, $table );
				break;
			case self::FORMAT_CSV:
				$this->streamCsvTable( $handle, $table );
				break;
		}
	}

	/**
	 * Stream SQL table export
	 *
	 * @param resource $handle File handle
	 * @param string $table Table name
	 */
	private function streamSqlTable( $handle, string $table ): void
	{
		$this->writeToHandle( $handle, "-- Table: {$table}\n" );

		$quoted = $this->quoteIdentifier( $table );

		if( $this->_Options['drop_tables'] )
		{
			$this->writeToHandle( $handle, "DROP TABLE IF EXISTS {$quoted};\n" );
		}

		if( $this->_Options['include_schema'] )
		{
			$this->writeToHandle( $handle, $this->getTableCreateStatement( $table ) . "\n" );
		}

		$this->writeToHandle( $handle, "DELETE FROM {$quoted};\n" );

		// Stream data in chunks
		$offset = 0;
		$batchSize = 1000;
		$userLimit = $this->_Options['limit'];
		$rowsProcessed = 0;

		while( true )
		{
			// Calculate the actual limit for this batch
			// If user specified a limit, respect it
			if( $userLimit !== null )
			{
				$remainingRows = $userLimit - $rowsProcessed;
				if( $remainingRows <= 0 )
				{
					break;
				}
				$limit = min( $batchSize, $remainingRows );
			}
			else
			{
				$limit = $batchSize;
			}

			// Normalize WHERE clause - treat empty/whitespace as no WHERE
			$whereClause = trim( $this->_Options['where'][$table] ?? '' );

			// Use prepared statements if PDO is available and WHERE clause exists
			if( $whereClause !== '' )
			{
				// Validate WHERE clause for SQL injection attempts
				if( !SqlWhereValidator::isValid( $whereClause ) )
				{
					throw new \InvalidArgumentException(
						"Potentially dangerous WHERE clause detected for table '{$table}'. " .
						"WHERE clauses must not contain SQL commands, comments, or subqueries."
					);
				}

				// Try to use PDO for prepared statements if available
				if( method_exists( $this->_Adapter, 'getConnection' ) )
				{
					$pdo = $this->_Adapter->getConnection();
					$parsed = $this->parseSimpleWhereClause( $whereClause );

					$quoted = $this->quoteIdentifier( $table );
					$sql = "SELECT * FROM {$quoted} WHERE " . $parsed['sql'] . " LIMIT {$limit} OFFSET {$offset}";
					$stmt = $pdo->prepare( $sql );
					$stmt->execute( $parsed['bindings'] );
					$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
				}
				else
				{
					// Fallback: Use validation + direct SQL (less secure)
					Log::warning( "PDO not available for prepared statements in DataExporter::streamSqlTable" );

					$quoted = $this->quoteIdentifier( $table );
					$sql = "SELECT * FROM {$quoted} WHERE " . $whereClause . " LIMIT {$limit} OFFSET {$offset}";
					$rows = $this->_Adapter->fetchAll( $sql );
				}
			}
			else
			{
				// No WHERE clause - safe to use direct SQL
				$quoted = $this->quoteIdentifier( $table );
				$sql = "SELECT * FROM {$quoted} LIMIT {$limit} OFFSET {$offset}";
				$rows = $this->_Adapter->fetchAll( $sql );
			}

			if( empty( $rows ) )
			{
				break;
			}

			// Process only the rows we fetched (might be less than batch size)
			$actualRowCount = count( $rows );

			// If user limit would be exceeded, truncate the rows
			if( $userLimit !== null )
			{
				$remainingRows = $userLimit - $rowsProcessed;
				if( $actualRowCount > $remainingRows )
				{
					$rows = array_slice( $rows, 0, $remainingRows );
					$actualRowCount = $remainingRows;
				}
			}

			$insertSql = $this->buildInsertStatements( $table, $rows );
			$this->writeToHandle( $handle, $insertSql . "\n" );

			$offset += $actualRowCount;
			$rowsProcessed += $actualRowCount;

			// Check if we've reached the user's limit
			if( $userLimit !== null && $rowsProcessed >= $userLimit )
			{
				break;
			}
		}

		$this->writeToHandle( $handle, "\n" );
	}

	/**
	 * Stream CSV table export
	 *
	 * @param resource $handle File handle
	 * @param string $table Table name
	 */
	private function streamCsvTable( $handle, string $table ): void
	{
		// Write table name as comment
		$this->writeToHandle( $handle, "# Table: {$table}\n" );

		// Stream data in chunks, similar to streamSqlTable
		$offset = 0;
		$batchSize = 1000;
		$userLimit = $this->_Options['limit'];
		$rowsProcessed = 0;
		$headerWritten = false;

		while( true )
		{
			// Calculate the actual limit for this batch
			// If user specified a limit, respect it
			if( $userLimit !== null )
			{
				$remainingRows = $userLimit - $rowsProcessed;
				if( $remainingRows <= 0 )
				{
					break;
				}
				$limit = min( $batchSize, $remainingRows );
			}
			else
			{
				$limit = $batchSize;
			}

			// Normalize WHERE clause - treat empty/whitespace as no WHERE
			$whereClause = trim( $this->_Options['where'][$table] ?? '' );

			// Use prepared statements if PDO is available and WHERE clause exists
			if( $whereClause !== '' )
			{
				// Validate WHERE clause for SQL injection attempts
				if( !SqlWhereValidator::isValid( $whereClause ) )
				{
					throw new \InvalidArgumentException(
						"Potentially dangerous WHERE clause detected for table '{$table}'. " .
						"WHERE clauses must not contain SQL commands, comments, or subqueries."
					);
				}

				// Try to use PDO for prepared statements if available
				if( method_exists( $this->_Adapter, 'getConnection' ) )
				{
					$pdo = $this->_Adapter->getConnection();
					$parsed = $this->parseSimpleWhereClause( $whereClause );

					$quoted = $this->quoteIdentifier( $table );
					$sql = "SELECT * FROM {$quoted} WHERE " . $parsed['sql'] . " LIMIT {$limit} OFFSET {$offset}";
					$stmt = $pdo->prepare( $sql );
					$stmt->execute( $parsed['bindings'] );
					$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
				}
				else
				{
					// Fallback: Use validation + direct SQL (less secure)
					Log::warning( "PDO not available for prepared statements in DataExporter::streamCsvTable" );

					$quoted = $this->quoteIdentifier( $table );
					$sql = "SELECT * FROM {$quoted} WHERE " . $whereClause . " LIMIT {$limit} OFFSET {$offset}";
					$rows = $this->_Adapter->fetchAll( $sql );
				}
			}
			else
			{
				// No WHERE clause - safe to use direct SQL
				$quoted = $this->quoteIdentifier( $table );
				$sql = "SELECT * FROM {$quoted} LIMIT {$limit} OFFSET {$offset}";
				$rows = $this->_Adapter->fetchAll( $sql );
			}

			if( empty( $rows ) )
			{
				break;
			}

			// Process only the rows we fetched (might be less than batch size)
			$actualRowCount = count( $rows );

			// If user limit would be exceeded, truncate the rows
			if( $userLimit !== null )
			{
				$remainingRows = $userLimit - $rowsProcessed;
				if( $actualRowCount > $remainingRows )
				{
					$rows = array_slice( $rows, 0, $remainingRows );
					$actualRowCount = $remainingRows;
				}
			}

			// Write header on first chunk
			if( !$headerWritten && !empty( $rows ) )
			{
				$this->writeCsvToHandle( $handle, array_keys( $rows[0] ) );
				$headerWritten = true;
			}

			// Write data rows
			foreach( $rows as $row )
			{
				$this->writeCsvToHandle( $handle, $row );
			}

			$offset += $actualRowCount;
			$rowsProcessed += $actualRowCount;

			// Check if we've reached the user's limit
			if( $userLimit !== null && $rowsProcessed >= $userLimit )
			{
				break;
			}
		}

		$this->writeToHandle( $handle, "\n" );
	}

	/**
	 * Write stream header
	 *
	 * @param resource $handle File handle
	 */
	private function writeStreamHeader( $handle ): void
	{
		switch( $this->_Options['format'] )
		{
			case self::FORMAT_SQL:
				$this->writeToHandle( $handle, "-- Neuron PHP Database Data Dump\n" );
				$this->writeToHandle( $handle, "-- Generated: " . date( 'Y-m-d H:i:s' ) . "\n" );
				$this->writeToHandle( $handle, "-- Database Type: " . $this->_AdapterType . "\n\n" );

				if( $this->_AdapterType === 'mysql' )
				{
					$this->writeToHandle( $handle, "SET FOREIGN_KEY_CHECKS = 0;\n" );
				}
				elseif( $this->_AdapterType === 'sqlite' )
				{
					$this->writeToHandle( $handle, "PRAGMA foreign_keys = OFF;\n" );
				}

				if( $this->_Options['use_transaction'] )
				{
					$this->writeToHandle( $handle, "\nBEGIN;\n\n" );
				}
				break;

			case self::FORMAT_CSV:
				$this->writeToHandle( $handle, "# Neuron PHP Database Data Export (CSV)\n" );
				$this->writeToHandle( $handle, "# Generated: " . date( 'Y-m-d H:i:s' ) . "\n\n" );
				break;
		}
	}

	/**
	 * Write stream table separator
	 *
	 * @param resource $handle File handle
	 */
	private function writeStreamTableSeparator( $handle ): void
	{
		// Add separator between tables if needed
		switch( $this->_Options['format'] )
		{
			case self::FORMAT_CSV:
				$this->writeToHandle( $handle, "\n" );
				break;
		}
	}

	/**
	 * Write stream footer
	 *
	 * @param resource $handle File handle
	 */
	private function writeStreamFooter( $handle ): void
	{
		switch( $this->_Options['format'] )
		{
			case self::FORMAT_SQL:
				if( $this->_Options['use_transaction'] )
				{
					$this->writeToHandle( $handle, "COMMIT;\n" );
				}

				if( $this->_AdapterType === 'mysql' )
				{
					$this->writeToHandle( $handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n" );
				}
				elseif( $this->_AdapterType === 'sqlite' )
				{
					$this->writeToHandle( $handle, "\nPRAGMA foreign_keys = ON;\n" );
				}
				break;
		}
	}

	/**
	 * Get list of all tables in database
	 *
	 * @return array
	 */
	private function getTables(): array
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				// Use PDO for parameter binding - Phinx adapters don't support it
				if( method_exists( $this->_Adapter, 'getConnection' ) )
				{
					try
					{
						$connection = $this->_Adapter->getConnection();
						if( $connection instanceof \PDO )
						{
							$sql = "SELECT TABLE_NAME FROM information_schema.TABLES
									WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
									ORDER BY TABLE_NAME";
							$stmt = $connection->prepare( $sql );
							if( $stmt !== false )
							{
								$stmt->execute( [$this->_Adapter->getOption( 'name' )] );
								$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
								return array_column( $rows, 'TABLE_NAME' );
							}
						}
					}
					catch( \Exception $e )
					{
						// Fall through to fallback
					}
				}

				// Fallback: Use basic escaping for database name
				// Database names are typically controlled by configuration, not user input,
				// so basic escaping is acceptable here
				$dbName = $this->_Adapter->getOption( 'name' ) ?? '';
				$dbName = str_replace( ["'", "\\"], ["''", "\\\\"], $dbName );
				$sql = "SELECT TABLE_NAME FROM information_schema.TABLES
						WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_TYPE = 'BASE TABLE'
						ORDER BY TABLE_NAME";
				$rows = $this->_Adapter->fetchAll( $sql );
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
