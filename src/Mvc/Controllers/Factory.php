<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Mvc\Application;

/**
 * Class Factory
 * @package Neuron\Mvc\Controllers
 *
 * Used to instantiate a new controller object.
 */
class Factory
{
	/**
	 * @param Application $App
	 * @param string $Name
	 * @param string $NameSpace = "\App\Controller"
	 * @return IController
	 * @throws NotFoundException
	 */
	static function create( Application $App, string $Name, $NameSpace = "\App\Controller" ) : IController
	{
		$Class = "$NameSpace\\$Name";

		if( !class_exists( $Class ) )
		{
			throw new NotFoundException( "Controller $Name not found.");
		}

		$Implements = class_implements( $Class );

		if( !in_array(IController::class, $Implements ) )
		{
			throw new NotFoundException( "$Name does not implement IController.");
		}

		return new $Class( $App->getRouter() );
	}
}
