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
	 * @param Application $App
	 * @param string $Class
	 * @return IController
	 * @throws NotFound
	 */
	static function create( Application $App, string $Class ) : IController
	{
		if( !class_exists( $Class ) )
		{
			throw new NotFound( "Controller $Class not found.");
		}

		$Implements = class_implements( $Class );

		if( !in_array(IController::class, $Implements ) )
		{
			throw new NotFound( "$Class does not implement IController.");
		}

		return new $Class( $App->getRouter() );
	}
}
