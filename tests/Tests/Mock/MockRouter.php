<?php

namespace Tests\Mock;

use Neuron\Routing\Router;
use Neuron\Routing\RouteMap;

/**
 * Mock Router for testing URL helpers.
 * 
 * This router implements the URL helper methods needed for testing,
 * even when the vendor routing package doesn't have them.
 */
class MockRouter extends Router
{
	private array $namedRoutes = [];

	/**
	 * Override to store routes with names for testing
	 */
	public function get( string $Route, $function, ?string $Filter = null ): RouteMap
	{
		$route = parent::get( $Route, $function, $Filter );
		return $route;
	}

	/**
	 * Override to store routes with names for testing
	 */
	public function post( string $Route, $function, ?string $Filter = null ): RouteMap
	{
		$route = parent::post( $Route, $function, $Filter );
		return $route;
	}

	/**
	 * Store a named route for testing
	 */
	public function addNamedRoute( string $name, string $path, string $method = 'GET' ): MockRouter
	{
		$this->namedRoutes[$name] = [
			'name' => $name,
			'path' => $path,
			'method' => $method
		];
		return $this;
	}

	/**
	 * Find a route by name (for URL helper testing)
	 */
	public function getRouteByName( string $name ): ?RouteMap
	{
		if( !isset( $this->namedRoutes[$name] ) )
		{
			return null;
		}

		$routeData = $this->namedRoutes[$name];
		$route = new RouteMap( $routeData['path'], function() { return 'test'; } );
		$route->setName( $name );
		
		return $route;
	}

	/**
	 * Generate URL from named route (for URL helper testing)
	 */
	public function generateUrl( string $name, array $parameters = [], bool $absolute = false ): ?string
	{
		$route = $this->getRouteByName( $name );
		
		if( !$route )
		{
			return null;
		}

		$path = $route->getPath();
		
		// Replace route parameters with actual values
		foreach( $parameters as $key => $value )
		{
			$path = str_replace( ':' . $key, $value, $path );
		}

		// If absolute URL requested, prepend base URL
		if( $absolute )
		{
			$baseUrl = \Neuron\Patterns\Registry::getInstance()->get( 'Base.Url' );
			if( $baseUrl )
			{
				return rtrim( $baseUrl, '/' ) . $path;
			}
		}

		return $path;
	}

	/**
	 * Get all named routes (for URL helper testing)
	 */
	public function getAllNamedRoutes(): array
	{
		$routes = [];
		
		foreach( $this->namedRoutes as $name => $routeData )
		{
			$routes[] = [
				'name' => $name,
				'method' => $routeData['method'],
				'path' => $routeData['path']
			];
		}
		
		return $routes;
	}
}