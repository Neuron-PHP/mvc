<?php

namespace Neuron\Mvc\Views;

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
 * @author Neuron-PHP Framework
 * @version 3.0.0
 * @since 1.0.0
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
	private string $_Layout;
	private string $_Controller;
	private string $_Page;
	private ?bool $_CacheEnabled = null;

	public function __construct()
	{}

	/**
	 * @return string
	 */
	public function getLayout(): string
	{
		return $this->_Layout;
	}

	/**
	 * @param string $Layout
	 * @return Html
	 */
	public function setLayout( string $Layout ): Base
	{
		$this->_Layout = $Layout;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getController(): string
	{
		return $this->_Controller;
	}

	/**
	 * @param string $Controller
	 * @return Html
	 */
	public function setController( string $Controller ): Base
	{
		$this->_Controller = $Controller;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPage(): string
	{
		return $this->_Page;
	}

	/**
	 * @param string $Page
	 * @return Html
	 */
	public function setPage( string $Page ): Base
	{
		$this->_Page = $Page;
		return $this;
	}

	/**
	 * Get cache enabled setting for this view instance
	 * 
	 * @return bool|null null means use global config, true/false overrides global
	 */
	public function getCacheEnabled(): ?bool
	{
		return $this->_CacheEnabled;
	}

	/**
	 * Set cache enabled setting for this view instance
	 * 
	 * @param bool|null $CacheEnabled null uses global config, true/false overrides
	 * @return Base
	 */
	public function setCacheEnabled( ?bool $CacheEnabled ): Base
	{
		$this->_CacheEnabled = $CacheEnabled;
		return $this;
	}
}
