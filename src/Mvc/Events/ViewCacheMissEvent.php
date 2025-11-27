<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event fired when a view must be rendered because no cache exists.
 *
 * This event is triggered when the view caching system does not find a
 * cached version of the requested view, requiring a full render.
 *
 * Use cases:
 * - Monitor cache miss rates and efficiency
 * - Identify pages that should be cached but aren't
 * - Track cold cache scenarios after deployment or cache clear
 * - Measure rendering performance on cache misses
 * - Optimize cache warming strategies
 * - Debug cache key generation and TTL issues
 *
 * @package Neuron\Mvc\Events
 */
class ViewCacheMissEvent implements IEvent
{
	/**
	 * @param string $controller Controller name
	 * @param string $page Page/view name
	 * @param string $cacheKey Cache key that was not found
	 */
	public function __construct(
		public readonly string $controller,
		public readonly string $page,
		public readonly string $cacheKey
	)
	{
	}

	public function getName(): string
	{
		return 'view.cache_miss';
	}
}
