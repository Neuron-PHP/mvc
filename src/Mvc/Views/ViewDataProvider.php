<?php

namespace Neuron\Mvc\Views;

use Neuron\Patterns\Registry;

/**
 * Global view data provider for the MVC framework.
 *
 * This class manages globally-shared view data that should be automatically
 * injected into all views. It eliminates the need for views to directly access
 * the Registry and provides a centralized place to configure view-level globals.
 *
 * 1. Register in an Initializer during application bootstrap
 * 2. Share data using share() method with static values or callables
 * 3. Data automatically injected into all views via Base::injectHelpers()
 *
 * @package Neuron\Mvc\Views
 *
 * @example
 * ```php
 * // In an Initializer:
 * $provider = ViewDataProvider::getInstance();
 *
 * // Static values
 * $provider->share('siteName', 'My Website');
 *
 * // Lazy evaluation (callable executed on each request)
 * $provider->share('currentUser', function() {
 *     return Registry::getInstance()->get('Auth.User');
 * });
 *
 * // Multiple values at once
 * $provider->shareMultiple([
 *     'theme' => 'default',
 *     'year' => fn() => date('Y')
 * ]);
 * ```
 */
class ViewDataProvider
{
	private array $_data = [];
	private static ?ViewDataProvider $_instance = null;

	/**
	 * Private constructor to enforce singleton pattern
	 */
	private function __construct()
	{}

	/**
	 * Get the singleton instance of ViewDataProvider.
	 *
	 * Creates and registers the instance in Registry if it doesn't exist.
	 *
	 * @return ViewDataProvider
	 */
	public static function getInstance(): ViewDataProvider
	{
		if( self::$_instance === null )
		{
			self::$_instance = new self();
			Registry::getInstance()->set( 'ViewDataProvider', self::$_instance );
		}

		return self::$_instance;
	}

	/**
	 * Share a value globally with all views.
	 *
	 * The value can be a static value or a callable. If a callable is provided,
	 * it will be executed each time the view data is resolved, allowing for
	 * dynamic values that change per request.
	 *
	 * @param string $key The key to store the data under
	 * @param mixed $value The value to share (can be callable for lazy evaluation)
	 * @return ViewDataProvider Fluent interface
	 *
	 * @example
	 * ```php
	 * $provider->share('siteName', 'My Website');
	 * $provider->share('currentUser', fn() => Auth::user());
	 * ```
	 */
	public function share( string $key, mixed $value ): ViewDataProvider
	{
		$this->_data[ $key ] = $value;
		return $this;
	}

	/**
	 * Share multiple values at once.
	 *
	 * @param array $data Associative array of key-value pairs
	 * @return ViewDataProvider Fluent interface
	 *
	 * @example
	 * ```php
	 * $provider->shareMultiple([
	 *     'siteName' => 'My Website',
	 *     'theme' => 'dark',
	 *     'currentYear' => fn() => date('Y')
	 * ]);
	 * ```
	 */
	public function shareMultiple( array $data ): ViewDataProvider
	{
		foreach( $data as $key => $value )
		{
			$this->share( $key, $value );
		}
		return $this;
	}

	/**
	 * Get a shared value by key.
	 *
	 * If the value is a callable, it will be executed and the result returned.
	 * If the key doesn't exist, returns the default value.
	 *
	 * @param string $key The key to retrieve
	 * @param mixed $default Default value if key doesn't exist
	 * @return mixed The resolved value
	 */
	public function get( string $key, mixed $default = null ): mixed
	{
		if( !$this->has( $key ) )
		{
			return $default;
		}

		$value = $this->_data[ $key ];

		// If value is callable, execute it and return result
		if( is_callable( $value ) )
		{
			return $value();
		}

		return $value;
	}

	/**
	 * Check if a key exists in the shared data.
	 *
	 * @param string $key The key to check
	 * @return bool True if key exists, false otherwise
	 */
	public function has( string $key ): bool
	{
		return array_key_exists( $key, $this->_data );
	}

	/**
	 * Get all shared data with callables resolved.
	 *
	 * This resolves all callables before returning, so the returned array
	 * contains only static values ready for view rendering.
	 *
	 * @return array Associative array of all shared data with resolved values
	 */
	public function all(): array
	{
		$resolved = [];

		foreach( $this->_data as $key => $value )
		{
			if( is_callable( $value ) )
			{
				$resolved[ $key ] = $value();
			}
			else
			{
				$resolved[ $key ] = $value;
			}
		}

		return $resolved;
	}

	/**
	 * Remove a shared value.
	 *
	 * @param string $key The key to remove
	 * @return ViewDataProvider Fluent interface
	 */
	public function remove( string $key ): ViewDataProvider
	{
		unset( $this->_data[ $key ] );
		return $this;
	}

	/**
	 * Clear all shared data.
	 *
	 * @return ViewDataProvider Fluent interface
	 */
	public function clear(): ViewDataProvider
	{
		$this->_data = [];
		return $this;
	}

	/**
	 * Get count of shared data items.
	 *
	 * @return int Number of shared items
	 */
	public function count(): int
	{
		return count( $this->_data );
	}
}
