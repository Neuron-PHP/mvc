<?php

namespace Neuron\Mvc\Helpers;

use Neuron\Core\NString;
use Neuron\Routing\Router;

/**
 * Rails-style URL helper for generating URLs from named routes.
 * 
 * This helper provides convenient methods for generating URLs from route names
 * with parameter substitution, supporting both relative and absolute URLs.
 * It integrates with the Neuron routing system to provide Rails-like helpers
 * for view templates and controllers.
 * 
 * Key features:
 * - Generate URLs from named routes with parameter substitution
 * - Support for both relative and absolute URL generation  
 * - Rails-style helper method naming (route_path, route_url)
 * - Integration with Router singleton for route lookup
 * - Error handling for missing routes and parameters
 * - Fluent interface for method chaining
 * 
 * URL helpers follow Rails conventions:
 * - `*_path()` methods generate relative URLs
 * - `*_url()` methods generate absolute URLs  
 * - Parameters are passed as associative arrays
 * - Missing routes return null for graceful error handling
 * 
 * @package Neuron\Mvc\Helpers
 * 
 * @example
 * ```php
 * $urlHelper = new UrlHelper();
 * 
 * // Generate relative URLs
 * echo $urlHelper->routePath('user_profile', ['id' => 123]);
 * // Output: /users/123
 * 
 * // Generate absolute URLs
 * echo $urlHelper->routeUrl('user_profile', ['id' => 123]);
 * // Output: https://example.com/users/123
 * 
 * // Rails-style magic methods
 * echo $urlHelper->userProfilePath(['id' => 123]);
 * echo $urlHelper->userProfileUrl(['id' => 123]);
 * ```
 */
class UrlHelper
{
	private Router $router;

	/**
	 * UrlHelper constructor.
	 * 
	 * @param Router|null $router Optional router instance (uses singleton if not provided)
	 */
	public function __construct( ?Router $router = null )
	{
		$this->router = $router ?: Router::instance();
	}

	/**
	 * Generate a relative URL for a named route.
	 * 
	 * @param string $routeName The name of the route
	 * @param array $parameters Parameters to substitute in the route path
	 * @return string|null The generated relative URL or null if route not found
	 */
	public function routePath( string $routeName, array $parameters = [] ): ?string
	{
		if( !method_exists( $this->router, 'generateUrl' ) )
		{
			return null;
		}
		return $this->router->generateUrl( $routeName, $parameters, false );
	}

	/**
	 * Generate an absolute URL for a named route.
	 * 
	 * @param string $routeName The name of the route
	 * @param array $parameters Parameters to substitute in the route path
	 * @return string|null The generated absolute URL or null if route not found
	 */
	public function routeUrl( string $routeName, array $parameters = [] ): ?string
	{
		if( !method_exists( $this->router, 'generateUrl' ) )
		{
			return null;
		}
		return $this->router->generateUrl( $routeName, $parameters, true );
	}

	/**
	 * Get the Router instance being used.
	 * 
	 * @return Router The router instance
	 */
	public function getRouter(): Router
	{
		return $this->router;
	}

	/**
	 * Set a different Router instance.
	 * 
	 * @param Router $router The router to use
	 * @return UrlHelper Fluent interface
	 */
	public function setRouter( Router $router ): UrlHelper
	{
		$this->router = $router;
		return $this;
	}

	/**
	 * Get all available named routes for debugging.
	 * 
	 * @return array Array of named route information
	 */
	public function getAvailableRoutes(): array
	{
		if( !method_exists( $this->router, 'getAllNamedRoutes' ) )
		{
			return [];
		}
		return $this->router->getAllNamedRoutes();
	}

	/**
	 * Check if a named route exists.
	 * 
	 * @param string $routeName The route name to check
	 * @return bool True if route exists, false otherwise
	 */
	public function routeExists( string $routeName ): bool
	{
		if( !method_exists( $this->router, 'getRouteByName' ) )
		{
			return false;
		}
		return $this->router->getRouteByName( $routeName ) !== null;
	}

	/**
	 * Magic method to provide Rails-style helper methods.
	 * 
	 * Converts camelCase method names to snake_case route names and generates URLs.
	 * Supports both *Path() and *Url() method patterns.
	 * 
	 * @param string $method The method name (e.g., 'userProfilePath', 'userProfileUrl')
	 * @param array $arguments Method arguments, first should be parameters array
	 * @return string|null Generated URL or null if route not found
	 * @throws \BadMethodCallException If method pattern is not recognized
	 * 
	 * @example
	 * ```php
	 * // These calls:
	 * $urlHelper->userProfilePath(['id' => 123]);
	 * $urlHelper->userProfileUrl(['id' => 123]);
	 * 
	 * // Are converted to:
	 * $urlHelper->routePath('user_profile', ['id' => 123]);
	 * $urlHelper->routeUrl('user_profile', ['id' => 123]);
	 * ```
	 */
	public function __call( string $method, array $arguments ): ?string
	{
		$parameters = $arguments[0] ?? [];

		// Handle *Path() methods
		if( preg_match( '/^(.+)Path$/', $method, $matches ) )
		{
			$routeName = ( new NString( $matches[1] ) )->toSnakeCase();
			return $this->routePath( $routeName, $parameters );
		}

		// Handle *Url() methods  
		if( preg_match( '/^(.+)Url$/', $method, $matches ) )
		{
			$routeName = ( new NString( $matches[1] ) )->toSnakeCase();
			return $this->routeUrl( $routeName, $parameters );
		}

		throw new \BadMethodCallException( "Method '$method' not found in UrlHelper" );
	}
}