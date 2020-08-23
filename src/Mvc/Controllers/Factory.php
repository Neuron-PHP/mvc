<?php

namespace Neuron\Mvc\Controllers;

class Factory
{
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
