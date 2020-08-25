<?php

namespace Neuron\Mvc\Controllers;

/**
 * Class Factory
 * @package Neuron\Mvc\Controllers
 *
 * Used to instantiate a new controller object.
 */
class Factory
{
	/**
	 * @param string $Name
	 * @param string $NameSpace = "\App\Controller"
	 * @return IController
	 * @throws NotFoundException
	 */
	static function create( string $Name, $NameSpace = "\App\Controller" ) : IController
	{
		$Class = "$NameSpace\\$Name";

		if( !class_exists( $Class ) )
		{
			throw new NotFoundException( "Controllers $Name not found.");
		}

		return new $Class;
	}
}
