<?php

namespace Neuron\Mvc\Components;

use Neuron\Mvc\IMvcApplication;

/**
 * Entry point of an installable web component.
 *
 * A Composer package becomes a web component by naming an implementation in
 * its composer.json:
 *
 *     "extra": { "neuron": { "mvc-provider": "Vendor\\Thing\\Provider" } }
 *
 * The MVC application discovers it from vendor/composer/installed.json at
 * boot (see ComponentLoader), scans its controller directories for route
 * attributes, appends its view roots, then calls register() for anything
 * else: route filters, container bindings, event listeners.
 *
 * Paths are declared rather than registered imperatively so tooling that
 * never boots an application (`routes:list`) can see the same routes.
 *
 * Site configuration (`routing.yaml` controller_paths, `views.path`) is
 * always scanned first, so a site controller or view wins over a component's
 * on a duplicate route name or view file.
 *
 * @package Neuron\Mvc\Components
 */
interface IComponentProvider
{
	/**
	 * Directories to scan for controllers with route attributes.
	 *
	 * @return list<array{path:string,namespace:string}> absolute directory and its PSR-4 namespace
	 */
	public static function controllerPaths(): array;

	/**
	 * View roots to append after the site's own.
	 *
	 * @return list<string> absolute directories
	 */
	public static function viewPaths(): array;

	/**
	 * Wiring that needs the live application: filters, services, listeners.
	 * Called after the component's routes and views are in place. Keep it
	 * cheap and side-effect free beyond registration; it runs on every request.
	 *
	 * @param IMvcApplication $app
	 * @return void
	 */
	public static function register( IMvcApplication $app ): void;
}
