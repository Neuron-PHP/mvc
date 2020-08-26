<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Routing\Router;

class Base implements IController
{
	private Router  $_Router;

	public function __construct( Router $Router )
	{
		$this->setRouter( $Router );
	}

	/**
	 * @return Router
	 */
	public function getRouter(): Router
	{
		return $this->_Router;
	}

	/**
	 * @param Router $Router
	 * @return Base
	 */
	public function setRouter( Router $Router ): Base
	{
		$this->_Router = $Router;
		return $this;
	}
}
