<?php

namespace Tests\Mvc\Database;

use Phinx\Db\Adapter\AdapterInterface;

/**
 * Phinx adapter double that also exposes the connection helpers the
 * importer and exporter call, which are not part of AdapterInterface.
 */
abstract class TestAdapterWithExtras implements AdapterInterface
{
	/**
	 * @return bool
	 */
	abstract public function hasTransaction(): bool;

	/**
	 * @return \PDO|null
	 */
	abstract public function getConnection();
}
