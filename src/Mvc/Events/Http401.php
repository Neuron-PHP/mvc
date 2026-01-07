<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event generated when a 401 Unauthorized error occurs.
 * Happens before the rendering of the error page.
 *
 * This event is emitted when authentication is required but not provided
 * or when provided credentials are invalid.
 *
 * @package Neuron\Mvc\Events
 */
class Http401 implements IEvent
{
	public string $route;
	public ?string $realm;

	/**
	 * @param string $route The route that triggered the 401 error
	 * @param string|null $realm Optional authentication realm (e.g., for WWW-Authenticate header)
	 */
	public function __construct( string $route, ?string $realm = null )
	{
		$this->route = $route;
		$this->realm = $realm;
	}
}