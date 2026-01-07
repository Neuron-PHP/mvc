<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event generated when a 403 Forbidden error occurs.
 * Happens before the rendering of the error page.
 *
 * This event is emitted when a user is authenticated but lacks the necessary
 * permissions to access a resource.
 *
 * @package Neuron\Mvc\Events
 */
class Http403 implements IEvent
{
	public string $route;
	public ?string $resource;
	public ?string $permission;

	/**
	 * @param string $route The route that triggered the 403 error
	 * @param string|null $resource The resource that was forbidden (e.g., 'User Profile', 'Document #123')
	 * @param string|null $permission The permission that was lacking (e.g., 'admin.edit', 'document.read')
	 */
	public function __construct( string $route, ?string $resource = null, ?string $permission = null )
	{
		$this->route = $route;
		$this->resource = $resource;
		$this->permission = $permission;
	}
}