<?php

namespace Neuron\Mvc\Views;

use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;

/**
 * Base view class for the Neuron MVC framework.
 * 
 * This abstract base class provides common functionality for all view types
 * in the MVC framework, including layout management, controller/page tracking,
 * and caching configuration. It serves as the foundation for specific view
 * implementations like HTML, JSON, XML, and Markdown views.
 * 
 * Key features:
 * - Layout template management for consistent page structure
 * - Controller and page identification for view resolution
 * - Individual view caching control (overrides global settings)
 * - Fluent interface for method chaining
 * - Foundation for multi-format view rendering
 * 
 * The view system supports hierarchical template resolution:
 * 1. Application-specific view directories
 * 2. Framework default view templates
 * 3. Layout wrapping with content injection
 * 
 * @package Neuron\Mvc\Views
 * 
 * @example
 * ```php
 * // Basic view configuration
 * $view = new Html();
 * $view->setLayout('main')
 *      ->setController('users')
 *      ->setPage('profile')
 *      ->setCacheEnabled(true);
 * 
 * // Render view with data
 * echo $view->render(['user' => $userData]);
 * ```
 */

class Base
{
	private string $_layout;
	private string $_controller;
	private string $_page;
	private ?bool $_cacheEnabled = null;
	protected IFileSystem $fs;

	public function __construct( ?IFileSystem $fs = null )
	{
		$this->fs = $fs ?? new RealFileSystem();
	}

	/**
	 * @return string
	 */
	public function getLayout(): string
	{
		return $this->_layout;
	}

	/**
	 * @param string $layout
	 * @return Html
	 */
	public function setLayout( string $layout ): Base
	{
		$this->_layout = $layout;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getController(): string
	{
		return $this->_controller;
	}

	/**
	 * @param string $controller
	 * @return Html
	 */
	public function setController( string $controller ): Base
	{
		$this->_controller = $controller;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPage(): string
	{
		return $this->_page;
	}

	/**
	 * @param string $page
	 * @return Html
	 */
	public function setPage( string $page ): Base
	{
		$this->_page = $page;
		return $this;
	}

	/**
	 * Get cache enabled setting for this view instance
	 * 
	 * @return bool|null null means use global config, true/false overrides global
	 */
	public function getCacheEnabled(): ?bool
	{
		return $this->_cacheEnabled;
	}

	/**
	 * Set cache enabled setting for this view instance
	 * 
	 * @param bool|null $cacheEnabled null uses global config, true/false overrides
	 * @return Base
	 */
	public function setCacheEnabled( ?bool $cacheEnabled ): Base
	{
		$this->_cacheEnabled = $cacheEnabled;
		return $this;
	}
}
