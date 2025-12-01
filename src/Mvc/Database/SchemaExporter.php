<?php

namespace Neuron\Mvc\Database;

use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports database schema to YAML format for reference purposes
 * Similar to Rails' schema.rb functionality
 */
class SchemaExporter
{
	private AdapterInterface $_Adapter;
	private string $_MigrationTable;

	/**
	 * @param Config $PhinxConfig Phinx configuration
	 * @param string $Environment Environment name
	 * @param string $MigrationTable Migration tracking table name
	 */
	public function __construct( Config $PhinxConfig, string $Environment, string $MigrationTable = 'phinx_log' )
	{
		$this->_MigrationTable = $MigrationTable;

		// Create database adapter from Phinx config
		$options = $PhinxConfig->getEnvironment( $Environment );
		$this->_Adapter = AdapterFactory::instance()->getAdapter(
			$options['adapter'],
			$options
		);

		// Connect to database
		$this->_Adapter->connect();
	}

	/**
	 * Export schema to YAML string
	 *
	 * @return string YAML representation of schema
	 */
	public function export(): string
	{
		$schema = $this->buildSchemaArray();

		return Yaml::dump( $schema, 4, 2 );
	}

	/**
	 * Export schema to file
	 *
	 * @param string $FilePath Path to output file
	 * @return bool Success status
	 */
	public function exportToFile( string $FilePath ): bool
	{
		$yaml = $this->export();

		$directory = dirname( $FilePath );
		if( !is_dir( $directory ) )
		{
			mkdir( $directory, 0755, true );
		}

		return file_put_contents( $FilePath, $yaml ) !== false;
	}

	/**
	 * Build schema array structure
	 *
	 * @return array
	 */
	private function buildSchemaArray(): array
	{
		$schema = [
			'version' => $this->getLatestMigrationVersion(),
			'tables' => []
		];

		$tables = $this->getTables();

		foreach( $tables as $tableName )
		{
			// Skip migration tracking table
			if( $tableName === $this->_MigrationTable )
			{
				continue;
			}

			$schema['tables'][$tableName] = $this->getTableSchema( $tableName );
		}

		return $schema;
	}

	/**
	 * Get latest migration version from tracking table
	 *
	 * @return string|null
	 */
	private function getLatestMigrationVersion(): ?string
	{
		if( !$this->_Adapter->hasTable( $this->_MigrationTable ) )
		{
			return null;
		}

		$result = $this->_Adapter->fetchRow(
			"SELECT version FROM {$this->_MigrationTable} ORDER BY version DESC LIMIT 1"
		);

		return $result ? (string)$result['version'] : null;
	}

	/**
	 * Get list of all tables in database
	 *
	 * @return array
	 */
	private function getTables(): array
	{
		$adapterType = $this->_Adapter->getAdapterType();

		switch( $adapterType )
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
				throw new \RuntimeException( "Unsupported adapter type: {$adapterType}" );
		}
	}

	/**
	 * Get schema for a specific table
	 *
	 * @param string $tableName
	 * @return array
	 */
	private function getTableSchema( string $tableName ): array
	{
		$tableSchema = [
			'columns' => $this->getColumns( $tableName )
		];

		$indexes = $this->getIndexes( $tableName );
		if( !empty( $indexes ) )
		{
			$tableSchema['indexes'] = $indexes;
		}

		$foreignKeys = $this->getForeignKeys( $tableName );
		if( !empty( $foreignKeys ) )
		{
			$tableSchema['foreign_keys'] = $foreignKeys;
		}

		return $tableSchema;
	}

	/**
	 * Get columns for a table
	 *
	 * @param string $tableName
	 * @return array
	 */
	private function getColumns( string $tableName ): array
	{
		$columns = [];
		$columnData = $this->_Adapter->getColumns( $tableName );

		foreach( $columnData as $column )
		{
			$columnInfo = [
				'type' => $this->normalizeType( $column->getType() ),
				'null' => $column->isNull()
			];

			// Add limit for string types
			if( $column->getLimit() !== null && $this->typeHasLimit( $column->getType() ) )
			{
				$columnInfo['limit'] = $column->getLimit();
			}

			// Add precision and scale for decimal types
			if( $column->getPrecision() !== null )
			{
				$columnInfo['precision'] = $column->getPrecision();
			}

			if( $column->getScale() !== null )
			{
				$columnInfo['scale'] = $column->getScale();
			}

			// Mark primary key
			if( $column->getIdentity() )
			{
				$columnInfo['primary'] = true;
				$columnInfo['auto_increment'] = true;
			}

			// Add default value
			$default = $column->getDefault();
			if( $default !== null )
			{
				$columnInfo['default'] = $default;
			}

			// Add comment if present
			$comment = $column->getComment();
			if( !empty( $comment ) )
			{
				$columnInfo['comment'] = $comment;
			}

			$columns[$column->getName()] = $columnInfo;
		}

		return $columns;
	}

	/**
	 * Get indexes for a table
	 *
	 * @param string $tableName
	 * @return array
	 */
	private function getIndexes( string $tableName ): array
	{
		$indexes = [];
		$indexData = $this->_Adapter->getIndexes( $tableName );

		foreach( $indexData as $index )
		{
			// Skip primary key (handled in column definition)
			if( $index->getType() === 'primary' )
			{
				continue;
			}

			$indexInfo = [
				'name' => $index->getName(),
				'columns' => $index->getColumns()
			];

			if( $index->getType() === 'unique' )
			{
				$indexInfo['unique'] = true;
			}

			$indexes[] = $indexInfo;
		}

		return $indexes;
	}

	/**
	 * Get foreign keys for a table
	 *
	 * @param string $tableName
	 * @return array
	 */
	private function getForeignKeys( string $tableName ): array
	{
		$foreignKeys = [];
		$fkData = $this->_Adapter->getForeignKeys( $tableName );

		foreach( $fkData as $fk )
		{
			$fkInfo = [
				'name' => $fk->getConstraint(),
				'columns' => $fk->getColumns(),
				'referenced_table' => $fk->getReferencedTable()->getName(),
				'referenced_columns' => $fk->getReferencedColumns()
			];

			if( $fk->getOnDelete() && $fk->getOnDelete() !== 'NO_ACTION' )
			{
				$fkInfo['on_delete'] = $fk->getOnDelete();
			}

			if( $fk->getOnUpdate() && $fk->getOnUpdate() !== 'NO_ACTION' )
			{
				$fkInfo['on_update'] = $fk->getOnUpdate();
			}

			$foreignKeys[] = $fkInfo;
		}

		return $foreignKeys;
	}

	/**
	 * Normalize column type names
	 *
	 * @param string $type
	 * @return string
	 */
	private function normalizeType( string $type ): string
	{
		// Map database-specific types to generic types
		$typeMap = [
			'biginteger' => 'bigint',
			'binaryuuid' => 'binary',
			'filestream' => 'binary',
		];

		$type = strtolower( $type );

		return $typeMap[$type] ?? $type;
	}

	/**
	 * Check if a type supports limit parameter
	 *
	 * @param string $type
	 * @return bool
	 */
	private function typeHasLimit( string $type ): bool
	{
		$typesWithLimit = [
			'string',
			'char',
			'binary',
			'varbinary'
		];

		return in_array( strtolower( $type ), $typesWithLimit );
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
