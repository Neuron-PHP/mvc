<?php

namespace Neuron\Mvc\Database;

use Neuron\Log\Log;
use Neuron\Orm\Query\QueryBuilder;
use PDO;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Database data exporter using ORM QueryBuilder for SQL injection protection
 *
 * This class exports database data to various formats (SQL, JSON, CSV, YAML)
 * with full support for WHERE conditions using parameterized queries.
 */
class DataExporterWithORM
{
	private PDO $_pdo;
	private AdapterInterface $_adapter;
	private string $_environment;
	private string $_migrationTable;
	private array $_options;

	/**
	 * Constructor
	 *
	 * @param Config $config Phinx configuration
	 * @param string $environment Environment name
	 * @param string $migrationTable Migration table name
	 * @param array $options Export options
	 */
	public function __construct( Config $config, string $environment, string $migrationTable, array $options = [] )
	{
		$this->_environment = $environment;
		$this->_migrationTable = $migrationTable;

		// Create adapter
		$envOptions = $config->getEnvironment( $environment );
		$this->_adapter = AdapterFactory::instance()->getAdapter(
			$envOptions['adapter'],
			$envOptions
		);

		// Connect to database
		$this->_adapter->connect();

		// Get PDO connection for QueryBuilder
		if( method_exists( $this->_adapter, 'getConnection' ) )
		{
			$this->_pdo = $this->_adapter->getConnection();
		}
		else
		{
			throw new \RuntimeException( 'Adapter does not provide PDO connection' );
		}

		// Set default options
		$this->_options = array_merge( [
			'format' => 'sql',
			'tables' => null,
			'exclude' => [],
			'limit' => null,
			'where' => [],
			'include_schema' => false,
			'drop_tables' => false,
			'use_transaction' => true,
			'compress' => false
		], $options );
	}

	/**
	 * Get data from a table using QueryBuilder
	 *
	 * @param string $table Table name
	 * @return array Table data
	 */
	private function getTableData( string $table ): array
	{
		$quoted = $this->quoteIdentifier( $table );
		$sql = "SELECT * FROM {$quoted}";
		$bindings = [];

		// Build WHERE clause with parameter binding
		if( isset( $this->_options['where'][$table] ) )
		{
			$whereClause = $this->_options['where'][$table];

			// Validate WHERE clause for SQL injection attempts
			if( !SqlWhereValidator::isValid( $whereClause ) )
			{
				throw new \InvalidArgumentException(
					"Potentially dangerous WHERE clause detected for table '{$table}'. " .
					"WHERE clauses must not contain SQL commands, comments, or subqueries."
				);
			}

			// Parse WHERE clause into conditions
			// This is a simplified parser - a full implementation would need more robust parsing
			$conditions = $this->parseWhereClause( $whereClause );

			if( !empty( $conditions['sql'] ) )
			{
				$sql .= " WHERE " . $conditions['sql'];
				$bindings = $conditions['bindings'];
			}
		}

		// Add LIMIT if specified
		if( $this->_options['limit'] !== null )
		{
			$sql .= " LIMIT " . (int)$this->_options['limit'];
		}

		// Execute with prepared statement
		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( $bindings );

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Parse a WHERE clause string into parameterized SQL
	 *
	 * This converts a WHERE clause like "status = 'active' AND age > 18"
	 * into parameterized SQL with bindings for safety.
	 *
	 * @param string $whereClause The WHERE clause to parse
	 * @return array Array with 'sql' and 'bindings' keys
	 */
	private function parseWhereClause( string $whereClause ): array
	{
		$sql = '';
		$bindings = [];

		// Pattern to match column operator value pairs
		// Note: Order matters - check multi-char operators (<=, >=, !=, <>) before single-char (<, >, =)
		$conditionPattern = '/(\w+)\s*(<=|>=|!=|<>|=|<|>|LIKE|NOT LIKE)\s*([\'"]?)([^\'"]*)\3/i';

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
				$value = $match[4];

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
	 * Get row count for a table using parameterized query
	 *
	 * @param string $table Table name
	 * @return int Row count
	 */
	public function getTableRowCount( string $table ): int
	{
		$quoted = $this->quoteIdentifier( $table );
		$sql = "SELECT COUNT(*) as count FROM {$quoted}";
		$bindings = [];

		// Build WHERE clause with parameter binding
		if( isset( $this->_options['where'][$table] ) )
		{
			$whereClause = $this->_options['where'][$table];

			// Validate WHERE clause for SQL injection attempts
			if( !SqlWhereValidator::isValid( $whereClause ) )
			{
				throw new \InvalidArgumentException(
					"Potentially dangerous WHERE clause detected for table '{$table}'. " .
					"WHERE clauses must not contain SQL commands, comments, or subqueries."
				);
			}

			$conditions = $this->parseWhereClause( $whereClause );

			if( !empty( $conditions['sql'] ) )
			{
				$sql .= " WHERE " . $conditions['sql'];
				$bindings = $conditions['bindings'];
			}
		}

		// Execute with prepared statement
		$stmt = $this->_pdo->prepare( $sql );
		$stmt->execute( $bindings );

		$result = $stmt->fetch( PDO::FETCH_ASSOC );

		// Check if query failed or table doesn't exist
		if( $result === false || !isset( $result['count'] ) )
		{
			Log::warning( "Could not fetch row count for table '{$table}' - table may have been dropped" );
			return 0;
		}

		return (int)$result['count'];
	}

	/**
	 * Export data to file
	 *
	 * @param string $outputPath Output file path
	 * @return string|false Actual output path or false on failure
	 */
	public function exportToFile( string $outputPath ): string|false
	{
		try
		{
			// Get list of tables to export
			$tables = $this->getTablesToExport();

			// Prepare output content based on format
			$content = '';

			switch( $this->_options['format'] )
			{
				case 'sql':
					$content = $this->exportToSql( $tables );
					break;
				case 'json':
					$content = $this->exportToJson( $tables );
					break;
				case 'csv':
					// CSV export requires one file per table
					return $this->exportToCsvDirectory( dirname( $outputPath ), $tables );
				case 'yaml':
					$content = $this->exportToYaml( $tables );
					break;
				default:
					throw new \InvalidArgumentException( "Unsupported format: {$this->_options['format']}" );
			}

			// Write to file (with compression if requested)
			if( $this->_options['compress'] )
			{
				$content = gzencode( $content );
				if( !str_ends_with( $outputPath, '.gz' ) )
				{
					$outputPath .= '.gz';
				}
			}

			if( file_put_contents( $outputPath, $content ) !== false )
			{
				return $outputPath;
			}

			return false;
		}
		catch( \Exception $e )
		{
			Log::error( "Export failed: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get list of tables to export
	 *
	 * @return array Table names
	 */
	private function getTablesToExport(): array
	{
		// Get all tables from database
		$sql = match( $this->_adapter->getAdapterType() )
		{
			'mysql' => "SELECT TABLE_NAME FROM information_schema.TABLES
			            WHERE TABLE_SCHEMA = :schema
			            AND TABLE_TYPE = 'BASE TABLE'",
			'sqlite' => "SELECT name FROM sqlite_master
			             WHERE type = 'table'
			             AND name NOT LIKE 'sqlite_%'",
			'pgsql' => "SELECT tablename FROM pg_tables
			            WHERE schemaname = 'public'",
			default => throw new \RuntimeException( "Unsupported adapter type" )
		};

		$stmt = $this->_pdo->prepare( $sql );

		if( $this->_adapter->getAdapterType() === 'mysql' )
		{
			$stmt->execute( ['schema' => $this->_adapter->getOption( 'name' )] );
		}
		else
		{
			$stmt->execute();
		}

		$tables = [];
		while( $row = $stmt->fetch( PDO::FETCH_ASSOC ) )
		{
			$tableName = $row['TABLE_NAME'] ?? $row['name'] ?? $row['tablename'] ?? null;
			if( $tableName )
			{
				$tables[] = $tableName;
			}
		}

		// Apply filters
		if( !empty( $this->_options['tables'] ) )
		{
			$tables = array_intersect( $tables, $this->_options['tables'] );
		}

		// Exclude migration table and any user-specified exclusions
		$excludes = array_merge( [$this->_migrationTable], $this->_options['exclude'] );
		$tables = array_diff( $tables, $excludes );

		return array_values( $tables );
	}

	/**
	 * Export to SQL format
	 *
	 * @param array $tables Tables to export
	 * @return string SQL content
	 */
	private function exportToSql( array $tables ): string
	{
		$sql = "-- Database export generated by DataExporterWithORM\n";
		$sql .= "-- Date: " . date( 'Y-m-d H:i:s' ) . "\n\n";

		if( $this->_options['use_transaction'] )
		{
			$sql .= "START TRANSACTION;\n\n";
		}

		foreach( $tables as $table )
		{
			// Add DROP TABLE if requested
			if( $this->_options['drop_tables'] )
			{
				$quoted = $this->quoteIdentifier( $table );
				$sql .= "DROP TABLE IF EXISTS {$quoted};\n";
			}

			// Add CREATE TABLE if requested
			if( $this->_options['include_schema'] )
			{
				$sql .= $this->getTableSchema( $table ) . "\n\n";
			}

			// Get table data using the private method
			$data = $this->getTableData( $table );

			if( !empty( $data ) )
			{
				$quotedTable = $this->quoteIdentifier( $table );
				$sql .= "-- Data for table {$table}\n";

				foreach( $data as $row )
				{
					$columns = array_keys( $row );
					$quotedColumns = array_map( [$this, 'quoteIdentifier'], $columns );
					$values = array_map( [$this, 'escapeValue'], array_values( $row ) );

					$sql .= sprintf(
						"INSERT INTO %s (%s) VALUES (%s);\n",
						$quotedTable,
						implode( ', ', $quotedColumns ),
						implode( ', ', $values )
					);
				}

				$sql .= "\n";
			}
		}

		if( $this->_options['use_transaction'] )
		{
			$sql .= "COMMIT;\n";
		}

		return $sql;
	}

	/**
	 * Export to JSON format
	 *
	 * @param array $tables Tables to export
	 * @return string JSON content
	 */
	private function exportToJson( array $tables ): string
	{
		$data = [];

		foreach( $tables as $table )
		{
			$tableData = $this->getTableData( $table );
			if( !empty( $tableData ) )
			{
				$data[$table] = $tableData;
			}
		}

		return json_encode( $data, JSON_PRETTY_PRINT );
	}

	/**
	 * Export to YAML format
	 *
	 * @param array $tables Tables to export
	 * @return string YAML content
	 */
	private function exportToYaml( array $tables ): string
	{
		$data = [];

		foreach( $tables as $table )
		{
			$tableData = $this->getTableData( $table );
			if( !empty( $tableData ) )
			{
				$data[$table] = $tableData;
			}
		}

		return Yaml::dump( $data, 4, 2 );
	}

	/**
	 * Export to CSV format (directory with one file per table)
	 *
	 * @param string $directory Output directory
	 * @param array $tables Tables to export
	 * @return string|false Directory path or false on failure
	 */
	private function exportToCsvDirectory( string $directory, array $tables ): string|false
	{
		if( !is_dir( $directory ) )
		{
			if( !mkdir( $directory, 0755, true ) )
			{
				return false;
			}
		}

		foreach( $tables as $table )
		{
			$data = $this->getTableData( $table );

			if( empty( $data ) )
			{
				continue;
			}

			$csvFile = $directory . '/' . $table . '.csv';
			$handle = fopen( $csvFile, 'w' );

			if( !$handle )
			{
				return false;
			}

			// Write headers
			fputcsv( $handle, array_keys( $data[0] ), ',', '"', '' );

			// Write data
			foreach( $data as $row )
			{
				fputcsv( $handle, $row, ',', '"', '' );
			}

			fclose( $handle );
		}

		return $directory;
	}

	/**
	 * Get table schema
	 *
	 * @param string $table Table name
	 * @return string CREATE TABLE statement
	 */
	private function getTableSchema( string $table ): string
	{
		switch( $this->_adapter->getAdapterType() )
		{
			case 'mysql':
				$quoted = $this->quoteIdentifier( $table );
				$stmt = $this->_pdo->prepare( "SHOW CREATE TABLE {$quoted}" );
				$stmt->execute();
				$result = $stmt->fetch( PDO::FETCH_ASSOC );
				return $result['Create Table'] ?? "-- Could not get schema for table {$table}";

			case 'sqlite':
				$stmt = $this->_pdo->prepare(
					"SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :table"
				);
				$stmt->execute( ['table' => $table] );
				$result = $stmt->fetch( PDO::FETCH_ASSOC );
				return $result['sql'] ?? "-- Could not get schema for table {$table}";

			case 'pgsql':
				// PostgreSQL schema export would be more complex
				return "-- PostgreSQL schema export not implemented for table {$table}";

			default:
				return "-- Schema export not supported for this database type";
		}
	}

	/**
	 * Escape value for SQL
	 *
	 * @param mixed $value Value to escape
	 * @return string Escaped value
	 */
	private function escapeValue( mixed $value ): string
	{
		if( $value === null )
		{
			return 'NULL';
		}

		if( is_bool( $value ) )
		{
			return $value ? '1' : '0';
		}

		if( is_numeric( $value ) && !$this->hasLeadingZeros( $value ) )
		{
			// Only treat as number if it doesn't have leading zeros
			return (string)$value;
		}

		// Use PDO quote for string values (including numeric strings with leading zeros)
		return $this->_pdo->quote( (string)$value );
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
		// Get adapter type
		$adapterType = $this->_adapter->getAdapterType();

		// Handle different database adapters
		switch( $adapterType )
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
	 * Disconnect from database
	 */
	public function disconnect(): void
	{
		$this->_adapter->disconnect();
	}

	/**
	 * Destructor - ensure adapter is disconnected
	 */
	public function __destruct()
	{
		if( isset( $this->_adapter ) )
		{
			try
			{
				$this->_adapter->disconnect();
			}
			catch( \Exception $e )
			{
				// Silently handle disconnect errors in destructor
			}
		}
	}
}