<?php

namespace Neuron\Mvc\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;
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
		// Apply .gz extension if compression is enabled
		$actualPath = $this->_Options['compress'] ? $FilePath . '.gz' : $FilePath;

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
				$sql[] = "DROP TABLE IF EXISTS `{$table}`;";
			}

			// Include schema if requested
			if( $this->_Options['include_schema'] )
			{
				$sql[] = $this->getTableCreateStatement( $table );
			}

			// Clear existing data
			$sql[] = "DELETE FROM `{$table}`;";

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
		$handle = fopen( $filePath, 'w' );
		if( !$handle )
		{
			return false;
		}

		try
		{
			// Get table data
			$data = $this->getTableData( $table );

			if( !empty( $data ) )
			{
				// Write header row
				fputcsv( $handle, array_keys( $data[0] ) );

				// Write data rows
				foreach( $data as $row )
				{
					fputcsv( $handle, $row );
				}
			}

			fclose( $handle );
			return true;
		}
		catch( \Exception $e )
		{
			fclose( $handle );
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

		if( isset( $this->_Options['where'][$table] ) )
		{
			$whereClause = $this->_Options['where'][$table];

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
				error_log( "WARNING: PDO not available for prepared statements in DataExporter" );

				$sql = "SELECT * FROM `{$table}` WHERE " . $whereClause;

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
			$sql = "SELECT * FROM `{$table}`";

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

		$sql = "SELECT * FROM `{$table}` WHERE " . $parsed['sql'];

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
		$pattern = '/(\w+)\s*(=|!=|<>|<|>|<=|>=|LIKE|NOT LIKE)\s*([\'"]?)([^\'"]*)\3/i';

		if( preg_match_all( $pattern, $whereClause, $matches, PREG_SET_ORDER ) )
		{
			$conditions = [];
			foreach( $matches as $match )
			{
				$column = $match[1];
				$operator = strtoupper( $match[2] );
				$value = $match[4];

				// Use placeholder for binding
				$conditions[] = "`{$column}` {$operator} ?";
				$bindings[] = $value;
			}

			// Determine logical operator (AND/OR)
			if( stripos( $whereClause, ' OR ' ) !== false )
			{
				$sql = implode( ' OR ', $conditions );
			}
			else
			{
				$sql = implode( ' AND ', $conditions );
			}
		}
		else
		{
			// If we can't parse it safely, throw an error
			throw new \InvalidArgumentException(
				"Cannot parse WHERE clause for parameterization: {$whereClause}. " .
				"Consider using the ORM QueryBuilder for complex queries."
			);
		}

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
		if( isset( $this->_Options['where'][$table] ) )
		{
			$whereClause = $this->_Options['where'][$table];

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

				$sql = "SELECT COUNT(*) as count FROM `{$table}` WHERE " . $parsed['sql'];
				$stmt = $pdo->prepare( $sql );
				$stmt->execute( $parsed['bindings'] );

				$result = $stmt->fetch( \PDO::FETCH_ASSOC );
				return (int)$result['count'];
			}
			else
			{
				// Fallback: Use validation + direct SQL (less secure)
				error_log( "WARNING: PDO not available for prepared statements in DataExporter::getTableRowCount" );

				$sql = "SELECT COUNT(*) as count FROM `{$table}` WHERE " . $whereClause;
				$result = $this->_Adapter->fetchRow( $sql );
				return (int)$result['count'];
			}
		}
		else
		{
			// No WHERE clause - safe to use direct SQL
			$sql = "SELECT COUNT(*) as count FROM `{$table}`";
			$result = $this->_Adapter->fetchRow( $sql );
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
		$columnList = '`' . implode( '`, `', $columns ) . '`';

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
						$escapedValues[] = $value ? '1' : '0';
					}
					elseif( is_numeric( $value ) )
					{
						$escapedValues[] = $value;
					}
					else
					{
						// Escape and quote string values
						$escapedValue = $this->escapeString( $value );
						$escapedValues[] = "'{$escapedValue}'";
					}
				}

				$values[] = '(' . implode( ', ', $escapedValues ) . ')';
			}

			$statements[] = "INSERT INTO `{$table}` ({$columnList}) VALUES\n" .
			                implode( ",\n", $values ) . ";";
		}

		return implode( "\n", $statements );
	}

	/**
	 * Escape string for SQL
	 *
	 * @param string $value Value to escape
	 * @return string Escaped value
	 */
	private function escapeString( string $value ): string
	{
		// Basic escaping - in production, use proper prepared statements
		return str_replace(
			["\\", "'", '"', "\n", "\r", "\t"],
			["\\\\", "''", '\"', "\\n", "\\r", "\\t"],
			$value
		);
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
				$result = $this->_Adapter->fetchRow( "SHOW CREATE TABLE `{$table}`" );
				return $result['Create Table'] . ";";

			case 'sqlite':
				$result = $this->_Adapter->fetchRow(
					"SELECT sql FROM sqlite_master WHERE type='table' AND name=?"
				, [$table] );
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
		fwrite( $handle, "-- Table: {$table}\n" );

		if( $this->_Options['drop_tables'] )
		{
			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
		}

		if( $this->_Options['include_schema'] )
		{
			fwrite( $handle, $this->getTableCreateStatement( $table ) . "\n" );
		}

		fwrite( $handle, "DELETE FROM `{$table}`;\n" );

		// Stream data in chunks
		$offset = 0;
		$limit = 1000;

		while( true )
		{
			// Use prepared statements if PDO is available and WHERE clause exists
			if( isset( $this->_Options['where'][$table] ) )
			{
				$whereClause = $this->_Options['where'][$table];

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

					$sql = "SELECT * FROM `{$table}` WHERE " . $parsed['sql'] . " LIMIT {$limit} OFFSET {$offset}";
					$stmt = $pdo->prepare( $sql );
					$stmt->execute( $parsed['bindings'] );
					$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
				}
				else
				{
					// Fallback: Use validation + direct SQL (less secure)
					error_log( "WARNING: PDO not available for prepared statements in DataExporter::streamSqlTable" );

					$sql = "SELECT * FROM `{$table}` WHERE " . $whereClause . " LIMIT {$limit} OFFSET {$offset}";
					$rows = $this->_Adapter->fetchAll( $sql );
				}
			}
			else
			{
				// No WHERE clause - safe to use direct SQL
				$sql = "SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}";
				$rows = $this->_Adapter->fetchAll( $sql );
			}

			if( empty( $rows ) )
			{
				break;
			}

			$insertSql = $this->buildInsertStatements( $table, $rows );
			fwrite( $handle, $insertSql . "\n" );

			$offset += $limit;

			// Apply overall limit if set
			if( $this->_Options['limit'] !== null && $offset >= $this->_Options['limit'] )
			{
				break;
			}
		}

		fwrite( $handle, "\n" );
	}

	/**
	 * Stream CSV table export
	 *
	 * @param resource $handle File handle
	 * @param string $table Table name
	 */
	private function streamCsvTable( $handle, string $table ): void
	{
		// For CSV streaming, this would write to separate files
		// This is a simplified implementation
		$data = $this->getTableData( $table );

		if( !empty( $data ) )
		{
			// Write table name as comment
			fwrite( $handle, "# Table: {$table}\n" );

			// Write header
			fputcsv( $handle, array_keys( $data[0] ) );

			// Write data
			foreach( $data as $row )
			{
				fputcsv( $handle, $row );
			}

			fwrite( $handle, "\n" );
		}
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
				fwrite( $handle, "-- Neuron PHP Database Data Dump\n" );
				fwrite( $handle, "-- Generated: " . date( 'Y-m-d H:i:s' ) . "\n" );
				fwrite( $handle, "-- Database Type: " . $this->_AdapterType . "\n\n" );

				if( $this->_AdapterType === 'mysql' )
				{
					fwrite( $handle, "SET FOREIGN_KEY_CHECKS = 0;\n" );
				}
				elseif( $this->_AdapterType === 'sqlite' )
				{
					fwrite( $handle, "PRAGMA foreign_keys = OFF;\n" );
				}

				if( $this->_Options['use_transaction'] )
				{
					fwrite( $handle, "\nBEGIN;\n\n" );
				}
				break;

			case self::FORMAT_CSV:
				fwrite( $handle, "# Neuron PHP Database Data Export (CSV)\n" );
				fwrite( $handle, "# Generated: " . date( 'Y-m-d H:i:s' ) . "\n\n" );
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
				fwrite( $handle, "\n" );
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
					fwrite( $handle, "COMMIT;\n" );
				}

				if( $this->_AdapterType === 'mysql' )
				{
					fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n" );
				}
				elseif( $this->_AdapterType === 'sqlite' )
				{
					fwrite( $handle, "\nPRAGMA foreign_keys = ON;\n" );
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