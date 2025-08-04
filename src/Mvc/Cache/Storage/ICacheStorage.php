<?php
namespace Neuron\Mvc\Cache\Storage;

interface ICacheStorage
{
	/**
	 * Read content from cache
	 *
	 * @param string $Key
	 * @return string|null
	 */
	public function read( string $Key ): ?string;

	/**
	 * Write content to cache
	 *
	 * @param string $Key
	 * @param string $Content
	 * @param int $Ttl
	 * @return bool
	 */
	public function write( string $Key, string $Content, int $Ttl ): bool;

	/**
	 * Check if cache key exists
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function exists( string $Key ): bool;

	/**
	 * Delete cache entry
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function delete( string $Key ): bool;

	/**
	 * Clear all cache entries
	 *
	 * @return bool
	 */
	public function clear(): bool;

	/**
	 * Check if cache entry is expired
	 *
	 * @param string $Key
	 * @return bool
	 */
	public function isExpired( string $Key ): bool;
}