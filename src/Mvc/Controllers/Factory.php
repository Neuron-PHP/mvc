<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Mvc\Application;
use Neuron\Patterns\Container\ContainerException;

/**
 * Used to instantiate a new controller object.
 *
 * Supports dependency injection via container if available,
 * otherwise falls back to basic instantiation.
 */
class Factory
{
	/**
	 * Create a controller instance
	 *
	 * @param Application $app
	 * @param string $class Fully qualified controller class name
	 * @return IController
	 * @throws NotFound If controller class doesn't exist or doesn't implement IController
	 */
	static function create( Application $app, string $class ) : IController
	{
		if( !class_exists( $class ) )
		{
			throw new NotFound( "Controller $class not found.");
		}

		$implements = class_implements( $class );

		if( !in_array(IController::class, $implements ) )
		{
			throw new NotFound( "$class does not implement IController.");
		}

		// Try container-based instantiation first (enables dependency injection)
		if( $app->hasContainer() )
		{
			try
			{
				return $app->getContainer()->make( $class );
			}
			catch( ContainerException $e )
			{
				// If container fails, fall back to basic instantiation
				// This allows gradual migration to dependency injection
			}
		}

		// Fallback: basic instantiation (legacy behavior)
		return new $class( $app );
	}
}
