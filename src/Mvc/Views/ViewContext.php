<?php

namespace Neuron\Mvc\Views;

use League\CommonMark\Exception\CommonMarkException;
use Neuron\Core\Exceptions\NotFound;
use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Patterns\Registry;

/**
 * Fluent view context builder for controllers.
 *
 * This class provides a clean, fluent API for building view data and rendering views.
 *
 * @package Neuron\Mvc\Views
 *
 * @example
 * ```php
 * // Basic usage
 * return $this->view()
 *     ->title('Dashboard')
 *     ->description('Admin Dashboard')
 *     ->with('posts', $posts)
 *     ->render('index', 'admin');
 *
 * // With multiple data items
 * return $this->view()
 *     ->title('Edit Post')
 *     ->with([
 *         'post' => $post,
 *         'categories' => $categories,
 *         'tags' => $tags
 *     ])
 *     ->render('edit');
 *
 * // Auto-inject user and CSRF token
 * return $this->view()
 *     ->title('Profile')
 *     ->withCurrentUser()
 *     ->withCsrfToken()
 *     ->render('profile');
 *
 * // Custom response status
 * return $this->view()
 *     ->status(HttpResponseStatus::CREATED)
 *     ->title('Post Created')
 *     ->with('post', $post)
 *     ->render('show');
 *
 * // Cache control
 * return $this->view()
 *     ->title('Homepage')
 *     ->cache(true)
 *     ->with('featured', $posts)
 *     ->render('index');
 * ```
 */
class ViewContext
{
	private Base $_controller;
	private Registry $_registry;
	private array $_data = [];
	private ?string $_title = null;
	private ?string $_description = null;
	private HttpResponseStatus $_status = HttpResponseStatus::OK;
	private ?bool $_cacheEnabled = null;
	private bool $_autoInjectUser = false;
	private bool $_autoInjectCsrf = false;

	/**
	 * @param Base $controller The controller instance
	 * @param Registry|null $registry Optional Registry instance for dependency injection (defaults to singleton)
	 */
	public function __construct( Base $controller, ?Registry $registry = null )
	{
		$this->_controller = $controller;
		$this->_registry = $registry ?? Registry::getInstance();
	}

	/**
	 * Set the page title.
	 *
	 * The title will be automatically concatenated with the site name
	 * when rendering (e.g., "Dashboard | My Site").
	 *
	 * @param string $title The page title
	 * @return ViewContext Fluent interface
	 */
	public function title( string $title ): ViewContext
	{
		$this->_title = $title;
		return $this;
	}

	/**
	 * Set the page description (meta description).
	 *
	 * @param string $description The page description
	 * @return ViewContext Fluent interface
	 */
	public function description( string $description ): ViewContext
	{
		$this->_description = $description;
		return $this;
	}

	/**
	 * Set the HTTP response status.
	 *
	 * @param HttpResponseStatus $status The HTTP status code
	 * @return ViewContext Fluent interface
	 */
	public function status( HttpResponseStatus $status ): ViewContext
	{
		$this->_status = $status;
		return $this;
	}

	/**
	 * Enable or disable view caching.
	 *
	 * @param bool $enabled Whether caching is enabled
	 * @return ViewContext Fluent interface
	 */
	public function cache( bool $enabled ): ViewContext
	{
		$this->_cacheEnabled = $enabled;
		return $this;
	}

	/**
	 * Add custom data to the view.
	 *
	 * Supports two usage patterns:
	 * 1. Key-value: ->with('posts', $posts)
	 * 2. Array merge: ->with(['posts' => $posts, 'count' => 10])
	 *
	 * @param string|array $key Key name or associative array of data
	 * @param mixed $value Value to set (ignored if $key is array)
	 * @return ViewContext Fluent interface
	 */
	public function with( string|array $key, mixed $value = null ): ViewContext
	{
		if( is_array( $key ) )
		{
			$this->_data = array_merge( $this->_data, $key );
		}
		else
		{
			$this->_data[ $key ] = $value;
		}

		return $this;
	}

	/**
	 * Auto-inject the current authenticated user into view data.
	 *
	 * The user will be available as $User in the view template.
	 * Looks up 'Auth.User' from Registry.
	 *
	 * @return ViewContext Fluent interface
	 */
	public function withCurrentUser(): ViewContext
	{
		$this->_autoInjectUser = true;
		return $this;
	}

	/**
	 * Auto-inject a CSRF token into view data and Registry.
	 *
	 * Looks up 'Auth.CsrfToken' from Registry and makes it available
	 * as $CsrfToken in the view template.
	 *
	 * For CMS applications, the token should be set via an initializer or
	 * middleware before controller execution.
	 *
	 * @return ViewContext Fluent interface
	 */
	public function withCsrfToken(): ViewContext
	{
		$this->_autoInjectCsrf = true;
		return $this;
	}

	/**
	 * Render the view as HTML.
	 *
	 * @param string $page The page template name
	 * @param string $layout The layout template name
	 * @return string The rendered HTML
	 * @throws NotFound If template not found
	 */
	public function renderHtml( string $page = 'index', string $layout = 'default' ): string
	{
		$data = $this->buildViewData();

		return $this->_controller->renderHtml(
			$this->_status,
			$data,
			$page,
			$layout,
			$this->_cacheEnabled
		);
	}

	/**
	 * Render the view as Markdown.
	 *
	 * @param string $page The page template name
	 * @param string $layout The layout template name
	 * @return string The rendered Markdown/HTML
	 * @throws NotFound If template not found
	 * @throws CommonMarkException If markdown parsing fails
	 */
	public function renderMarkdown( string $page = 'index', string $layout = 'default' ): string
	{
		$data = $this->buildViewData();

		return $this->_controller->renderMarkdown(
			$this->_status,
			$data,
			$page,
			$layout,
			$this->_cacheEnabled
		);
	}

	/**
	 * Render the view as JSON.
	 *
	 * Note: title and description are ignored for JSON rendering.
	 *
	 * @return string The rendered JSON
	 */
	public function renderJson(): string
	{
		$data = $this->buildViewData( false );

		return $this->_controller->renderJson(
			$this->_status,
			$data
		);
	}

	/**
	 * Render the view as XML.
	 *
	 * Note: title and description are ignored for XML rendering.
	 *
	 * @return string The rendered XML
	 */
	public function renderXml(): string
	{
		$data = $this->buildViewData( false );

		return $this->_controller->renderXml(
			$this->_status,
			$data
		);
	}

	/**
	 * Alias for renderHtml() - the default render method.
	 *
	 * @param string $page The page template name
	 * @param string $layout The layout template name
	 * @return string The rendered HTML
	 * @throws NotFound If template not found
	 */
	public function render( string $page = 'index', string $layout = 'default' ): string
	{
		return $this->renderHtml( $page, $layout );
	}

	/**
	 * Build the final view data array.
	 *
	 * This method:
	 * 1. Adds title and description (if set and $includeMetadata is true)
	 * 2. Auto-injects user if requested
	 * 3. Auto-injects CSRF token if requested
	 * 4. Merges with custom data provided via with()
	 *
	 * Note: The resulting array will be further processed by Base::injectHelpers()
	 * which merges ViewDataProvider global data and adds UrlHelper.
	 *
	 * @param bool $includeMetadata Whether to include title/description
	 * @return array The view data array
	 */
	private function buildViewData( bool $includeMetadata = true ): array
	{
		$data = $this->_data;

		// Add title and description for HTML/Markdown views
		if( $includeMetadata )
		{
			if( $this->_title !== null )
			{
				// Get site name from controller's getName() method
				$siteName = method_exists( $this->_controller, 'getName' )
					? $this->_controller->getName()
					: '';

				$data['Title'] = $siteName
					? $this->_title . ' | ' . $siteName
					: $this->_title;
			}

			if( $this->_description !== null )
			{
				$data['Description'] = $this->_description;
			}
		}

		// Auto-inject current user
		if( $this->_autoInjectUser )
		{
			$user = $this->_registry->get( 'Auth.User' );
			if( $user )
			{
				$data['User'] = $user;
			}
		}

		// Auto-inject CSRF token from Registry
		if( $this->_autoInjectCsrf )
		{
			$token = $this->_registry->get( 'Auth.CsrfToken' );
			if( $token )
			{
				$data['CsrfToken'] = $token;
			}
		}

		return $data;
	}

	/**
	 * Get the controller instance.
	 *
	 * @return Base
	 */
	public function getController(): Base
	{
		return $this->_controller;
	}

	/**
	 * Get the current view data (without metadata).
	 *
	 * Useful for debugging or testing.
	 *
	 * @return array
	 */
	public function getData(): array
	{
		return $this->_data;
	}

	/**
	 * Get the current title.
	 *
	 * @return string|null
	 */
	public function getTitle(): ?string
	{
		return $this->_title;
	}

	/**
	 * Get the current description.
	 *
	 * @return string|null
	 */
	public function getDescription(): ?string
	{
		return $this->_description;
	}

	/**
	 * Get the HTTP response status.
	 *
	 * @return HttpResponseStatus
	 */
	public function getStatus(): HttpResponseStatus
	{
		return $this->_status;
	}
}
