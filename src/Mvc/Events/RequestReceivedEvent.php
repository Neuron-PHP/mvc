<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event fired when the MVC application receives an HTTP request.
 *
 * This event is triggered at the beginning of the request lifecycle,
 * before routing and controller execution.
 *
 * Use cases:
 * - Request logging and analytics
 * - Track request volume and patterns
 * - Implement custom request filtering or preprocessing
 * - Generate audit trails for compliance
 * - Monitor API usage and endpoints
 * - Track user behavior and navigation patterns
 *
 * @package Neuron\Mvc\Events
 */
class RequestReceivedEvent implements IEvent
{
	/**
	 * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
	 * @param string $route Requested route/path
	 * @param string $ip Client IP address
	 * @param float $timestamp Request timestamp (microtime)
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $route,
		public readonly string $ip,
		public readonly float $timestamp
	)
	{
	}

	public function getName(): string
	{
		return 'request.received';
	}
}
