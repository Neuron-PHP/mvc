<?php

namespace Neuron\Mvc;

use Neuron\Application\IApplication;
use Neuron\Patterns\Container\IContainer;
use Neuron\Routing\Router;

/**
 * MVC Application Interface
 *
 * Extends the base application interface with MVC-specific capabilities
 * including routing and dependency injection container access.
 *
 * @package Neuron\Mvc
 */
interface IMvcApplication extends IApplication
{
	/**
	 * Get the application router instance
	 *
	 * @return Router
	 */
	public function getRouter(): Router;

	/**
	 * Get the dependency injection container
	 *
	 * @return IContainer
	 * @throws \Exception If container has not been set
	 */
	public function getContainer(): IContainer;

	/**
	 * Check if a dependency injection container has been configured
	 *
	 * @return bool
	 */
	public function hasContainer(): bool;

	/**
	 * Scan a directory for controllers with route attributes and register
	 * their routes. Programmatic equivalent of a `controller_paths` entry,
	 * used by web components; routes already registered keep precedence.
	 *
	 * @param string $directory Absolute path
	 * @param string $namespace PSR-4 namespace of the classes in it
	 * @return int Number of routes registered
	 */
	public function addControllerPath( string $directory, string $namespace ): int;
}
