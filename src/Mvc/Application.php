<?php

namespace Neuron\Mvc;

use Neuron\Core\Application\Base;
use Neuron\Routing\Router;

/**
 * Class Application
 * @package Mvc
 */
class Application extends Base
{
	private Router $_Router;

	protected function onRun()
	{
		$this->_Router->run( $this->getParameters() );
	}

	public function getVersion()
	{
	}
}
