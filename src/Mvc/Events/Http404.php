<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event generated when a 404 takes place.
 * Happens before the rendering of the page.
 */
class Http404 implements IEvent
{
	public string $Route;

	public function __construct( string $Route )
	{
		$this->Route = $Route;
	}
}
