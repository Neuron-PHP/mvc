<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event generated when a 404 takes place.
 * Happens before the rendering of the page.
 */
class Http404 implements IEvent
{
	public string $route;

	public function __construct( string $route )
	{
		$this->route = $route;
	}
}
