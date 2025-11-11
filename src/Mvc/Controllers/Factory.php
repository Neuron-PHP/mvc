<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Mvc\Application;

/**
 * Used to instantiate a new controller object.
 */
class Factory
{
	/**
	 * @param Application $app
	 * @param string $class
	 * @return IController
	 * @throws NotFound
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

		return new $class( $app );
	}
}
