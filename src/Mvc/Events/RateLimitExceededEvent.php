<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event fired when a request exceeds rate limit thresholds.
 *
 * This event is triggered by the RateLimitFilter when a client (identified
 * by IP address or other key) exceeds the configured rate limit for a route
 * or API endpoint.
 *
 * Use cases:
 * - Security monitoring and abuse detection
 * - Automatically block or throttle abusive IPs
 * - Alert administrators of potential DDoS attacks
 * - Track rate limit patterns and adjust thresholds
 * - Generate security reports for compliance
 * - Trigger CAPTCHA challenges for suspicious traffic
 *
 * @package Neuron\Mvc\Events
 */
class RateLimitExceededEvent implements IEvent
{
	/**
	 * @param string $ip Client IP address that exceeded the limit
	 * @param string $route Route or endpoint that was rate limited
	 * @param int $limit Maximum requests allowed
	 * @param int $window Time window in seconds
	 * @param int $attempts Number of requests made in the window
	 */
	public function __construct(
		public readonly string $ip,
		public readonly string $route,
		public readonly int $limit,
		public readonly int $window,
		public readonly int $attempts
	)
	{
	}

	public function getName(): string
	{
		return 'rate_limit.exceeded';
	}
}
