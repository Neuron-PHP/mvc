<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event fired when a rendered view is served from cache.
 *
 * This event is triggered when the view caching system finds a valid
 * cached version of the requested view and returns it without rendering.
 *
 * Use cases:
 * - Monitor cache effectiveness and hit rates
 * - Track performance improvements from caching
 * - Identify which pages benefit most from caching
 * - Optimize cache TTL based on hit patterns
 * - Generate cache performance reports
 * - Debug caching behavior and cache key generation
 *
 * @package Neuron\Mvc\Events
 */
class ViewCacheHitEvent implements IEvent
{
	/**
	 * @param string $controller Controller name
	 * @param string $page Page/view name
	 * @param string $cacheKey Cache key used for lookup
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
		return 'view.cache_hit';
	}
}
