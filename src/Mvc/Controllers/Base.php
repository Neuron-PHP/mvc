<?php

namespace Neuron\Mvc\Controllers;

use League\CommonMark\Exception\CommonMarkException;
use Neuron\Core\Exceptions\BadRequestMethod;
use Neuron\Data\Filter\Get;
use Neuron\Data\Filter\Post;
use Neuron\Data\Filter\Server;
use Neuron\Data\Setting\SettingManager;
use Neuron\Mvc\Application;
use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\CacheStorageFactory;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Mvc\Helpers\UrlHelper;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Mvc\Views\Html;
use Neuron\Mvc\Views\Json;
use Neuron\Mvc\Views\Markdown;
use Neuron\Mvc\Views\NotFound;
use Neuron\Mvc\Views\Xml;
use Neuron\Routing\Router;

class Base implements IController
{
	private Router  $_router;
	private Application $_app;

	/**
	 * @return Application
	 */
	public function getApplication(): Application
	{
		return $this->_app;
	}

	/**
	 * @param Application $app
	 * @return Base
	 */
	public function setApplication( Application $app ): Base
	{
		$this->_app = $app;
		return $this;
	}

	/**
	 * @param Application $app
	 */
	public function __construct( ?Application $app = null )
	{
		if( $app )
		{
			$this->setRouter( $app->getRouter() );
			$this->setApplication( $app );
		}
	}

	/**
	 * @param HttpResponseStatus $responseCode
	 * @param array $data
	 * @param string $page
	 * @param string $layout
	 * @param bool|null $cacheEnabled
	 * @return string
	 * @throws CommonMarkException
	 * @throws \Neuron\Core\Exceptions\NotFound
	 */
	public function renderMarkdown( HttpResponseStatus $responseCode, array $data = [], string $page = "index", string $layout = "default", ?bool $cacheEnabled = null ) : string
	{
		@http_response_code( $responseCode->value );

		$view = new Markdown()
			->setController( $this->getControllerViewPath() )
			->setLayout( $layout )
			->setPage( $page )
			->setCacheEnabled( $cacheEnabled );

		$dataWithHelpers = $this->injectHelpers( $data );

		return $view->render( $dataWithHelpers );
	}

	/**
	 * Inject URL helpers and other view helpers into view data.
	 *
	 * This method:
	 * 1. Merges global view data from ViewDataProvider (if registered)
	 * 2. Injects UrlHelper for route generation (if router available)
	 * 3. Controller-specific data takes precedence over global data
	 *
	 * @param array $data The view data array
	 * @return array Data array with helpers and global data injected
	 */
	protected function injectHelpers( array $data ): array
	{
		$registry = \Neuron\Patterns\Registry::getInstance();

		// Merge global view data from ViewDataProvider if available
		$viewDataProvider = $registry->get( 'ViewDataProvider' );
		if( $viewDataProvider instanceof \Neuron\Mvc\Views\ViewDataProvider )
		{
			// Global data first, then controller data (controller data wins)
			$data = array_merge( $viewDataProvider->all(), $data );
		}

		// Only inject UrlHelper if router is available
		if( isset( $this->_router ) )
		{
			$data['urlHelper'] = new UrlHelper( $this->_router );
		}

		return $data;
	}

	/**
	 * @param HttpResponseStatus $responseCode
	 * @param array $data
	 * @param string $page
	 * @param string $layout
	 * @param bool|null $cacheEnabled
	 * @return string
	 * @throws \Neuron\Core\Exceptions\NotFound
	 */
	public function renderHtml( HttpResponseStatus $responseCode, array $data = [], string $page = "index", string $layout = "default", ?bool $cacheEnabled = null ) : string
	{
		@http_response_code( $responseCode->value );

		$view = new Html()
			->setController( $this->getControllerViewPath() )
			->setLayout( $layout )
			->setPage( $page )
			->setCacheEnabled( $cacheEnabled );

		$dataWithHelpers = $this->injectHelpers( $data );

		return $view->render( $dataWithHelpers );
	}

	/**
	 * @param HttpResponseStatus $responseCode
	 * @param array $data
	 * @return string
	 */
	public function renderJson( HttpResponseStatus $responseCode, array $data = [] ): string
	{
		@http_response_code( $responseCode->value );

		$view = new Json();

		return $view->render( $data );
	}

	/**
	 * @param HttpResponseStatus $responseCode
	 * @param array $data
	 * @return string
	 */
	public function renderXml( HttpResponseStatus $responseCode, array $data = [] ): string
	{
		@http_response_code( $responseCode->value );

		$view = new Xml();

		return $view->render( $data );
	}

	/**
	 * @return Router
	 */
	public function getRouter(): Router
	{
		return $this->_router;
	}

	/**
	 * @param Router $router
	 * @return Base
	 */
	public function setRouter( Router $router ): Base
	{
		$this->_router = $router;
		return $this;
	}

	/**
	 * Get the controller name for cache key generation.
	 * Returns the short class name (without namespace).
	 * This matches how the framework's render methods set the controller name.
	 *
	 * @return string The controller class name without namespace
	 */
	protected function getControllerName(): string
	{
		// Using static::class ensures this returns the actual derived class name
		// not "Base", even when called from this base class
		return new \ReflectionClass( static::class )->getShortName();
	}

	/**
	 * Get the controller view path accounting for namespace hierarchy.
	 * Converts controller namespace and class name to snake_case directory structure.
	 *
	 * Examples:
	 * - Neuron\Cms\Controllers\Admin\Posts -> admin/posts
	 * - Neuron\Cms\Controllers\PostController -> post (backwards compatible with "Controller" suffix)
	 * - Neuron\Cms\Controllers\Dashboard -> dashboard
	 *
	 * @return string The view path (e.g., "admin/posts", "dashboard")
	 */
	protected function getControllerViewPath(): string
	{
		$reflection = new \ReflectionClass( static::class );
		$fullClassName = $reflection->getName();

		// Find the position of "Controllers" in the namespace
		$controllersPos = strrpos( $fullClassName, '\\Controllers\\' );

		if( $controllersPos === false )
		{
			// No "Controllers" namespace found, fall back to short name
			$shortName = $reflection->getShortName();
			// Strip "Controller" suffix for backwards compatibility
			$shortName = preg_replace( '/Controller$/', '', $shortName );
			return ( new \Neuron\Core\NString( $shortName ) )->toSnakeCase();
		}

		// Extract everything after "Controllers\"
		$afterControllers = substr( $fullClassName, $controllersPos + strlen( '\\Controllers\\' ) );

		// Split by namespace separator
		$parts = explode( '\\', $afterControllers );

		// Convert each part to snake_case
		$snakeCaseParts = array_map( function( $part ) {
			// Strip "Controller" suffix for backwards compatibility
			$part = preg_replace( '/Controller$/', '', $part );
			return ( new \Neuron\Core\NString( $part ) )->toSnakeCase();
		}, $parts );

		// Join with forward slashes for directory path
		return implode( '/', $snakeCaseParts );
	}

	/**
	 * Initialize ViewCache if not already present in Registry.
	 * This allows controllers to check cache before making expensive API calls.
	 * 
	 * @return ViewCache|null The ViewCache instance or null if initialization fails
	 */
	protected function initializeViewCache(): ?ViewCache
	{
		$registry = \Neuron\Patterns\Registry::getInstance();
		
		// Check if cache is already initialized
		$viewCache = $registry->get( 'ViewCache' );
		if( $viewCache !== null )
		{
			return $viewCache;
		}
		
		// Try to create cache from settings
		$settings = $registry->get( 'Settings' );
		if( $settings === null )
		{
			return null;
		}
		
		// Handle both SettingManager and ISettingSource
		if( $settings instanceof SettingManager )
		{
			$settingSource = $settings->getSource();
		}
		else
		{
			$settingSource = $settings;
		}
		
		$config = CacheConfig::fromSettings( $settingSource );
		
		if( !$config->isEnabled() )
		{
			return null;
		}
		
		try
		{
			$basePath = $registry->get( 'Base.Path' ) ?? '.';

			$storage = CacheStorageFactory::createFromConfig( $config, $basePath );
			$viewCache = new ViewCache(
				$storage,
				true,
				$config->getDefaultTtl(),
				$config
			);

			$registry->set( 'ViewCache', $viewCache );

			return $viewCache;
		}
		catch( CacheException $e )
		{
			// Unable to initialize cache
			return null;
		}
	}

	/**
	 * Check if view cache exists for the given page and data.
	 * Initializes ViewCache if needed.
	 * 
	 * @param string $page The page/view name
	 * @param array $data The data that affects cache key generation
	 * @return bool True if cache exists, false otherwise
	 */
	protected function hasViewCache( string $page, array $data = [] ): bool
	{
		$viewCache = $this->initializeViewCache();
		
		if( !$viewCache || !$viewCache->isEnabled() )
		{
			return false;
		}
		
		$cacheKey = $viewCache->generateKey(
			$this->getControllerName(),
			$page,
			$data
		);
		
		return $viewCache->exists( $cacheKey );
	}

	/**
	 * Get cached view content if available.
	 * Initializes ViewCache if needed.
	 * 
	 * @param string $page The page/view name
	 * @param array $data The data that affects cache key generation
	 * @return string|null The cached content or null if not found
	 */
	protected function getViewCache( string $page, array $data = [] ): ?string
	{
		$viewCache = $this->initializeViewCache();
		
		if( !$viewCache || !$viewCache->isEnabled() )
		{
			return null;
		}
		
		$cacheKey = $viewCache->generateKey(
			$this->getControllerName(),
			$page,
			$data
		);
		
		return $viewCache->get( $cacheKey );
	}

	/**
	 * Check if cache is enabled by default in system settings.
	 * 
	 * @return bool True if cache is enabled in settings, false otherwise
	 */
	protected function isCacheEnabledByDefault(): bool
	{
		$viewCache = $this->initializeViewCache();
		return $viewCache && $viewCache->isEnabled();
	}

	/**
	 * Check if view cache exists using only cache key data.
	 * This allows checking cache without fetching full view data.
	 * 
	 * @param string $page The page/view name
	 * @param array $cacheKeyData The minimal data that determines cache uniqueness
	 * @return bool True if cache exists, false otherwise
	 */
	protected function hasViewCacheByKey( string $page, array $cacheKeyData = [] ): bool
	{
		$viewCache = $this->initializeViewCache();
		
		if( !$viewCache || !$viewCache->isEnabled() )
		{
			return false;
		}
		
		$cacheKey = $viewCache->generateKey(
			$this->getControllerName(),
			$page,
			$cacheKeyData
		);
		
		return $viewCache->exists( $cacheKey );
	}

	/**
	 * Get cached view content using only cache key data.
	 * This allows retrieving cache without fetching full view data.
	 * 
	 * @param string $page The page/view name
	 * @param array $cacheKeyData The minimal data that determines cache uniqueness
	 * @return string|null The cached content or null if not found
	 */
	protected function getViewCacheByKey( string $page, array $cacheKeyData = [] ): ?string
	{
		$viewCache = $this->initializeViewCache();
		
		if( !$viewCache || !$viewCache->isEnabled() )
		{
			return null;
		}
		
		$cacheKey = $viewCache->generateKey(
			$this->getControllerName(),
			$page,
			$cacheKeyData
		);
		
		return $viewCache->get( $cacheKey );
	}

	/**
	 * Render HTML with separate cache key data.
	 * Allows checking/using cache without fetching full view data.
	 * 
	 * @param HttpResponseStatus $responseCode HTTP response status
	 * @param array $viewData Full data for rendering (can be empty if using cache)
	 * @param array $cacheKeyData Minimal data for cache key generation
	 * @param string $page Page template name
	 * @param string $layout Layout template name
	 * @param bool|null $cacheEnabled Whether to enable caching
	 * @return string Rendered HTML content
	 * @throws \Neuron\Core\Exceptions\NotFound
	 */
	public function renderHtmlWithCacheKey( 
		HttpResponseStatus $responseCode, 
		array $viewData = [], 
		array $cacheKeyData = [], 
		string $page = "index", 
		string $layout = "default", 
		?bool $cacheEnabled = null 
	): string
	{
		@http_response_code( $responseCode->value );

		// Determine if cache should be used based on explicit setting or system default
		$shouldUseCache = $cacheEnabled !== false && 
		                  ( $cacheEnabled === true || $this->isCacheEnabledByDefault() );

		// If view data is empty and cache should be used, try to get cached content
		if( empty( $viewData ) && $shouldUseCache )
		{
			$cachedContent = $this->getViewCacheByKey( $page, $cacheKeyData );
			if( $cachedContent !== null )
			{
				return $cachedContent;
			}
		}

		// Create view and set up for rendering
		$view = new Html()
			->setController( $this->getControllerViewPath() )
			->setLayout( $layout )
			->setPage( $page )
			->setCacheEnabled( $cacheEnabled );

		// If we have view data, render normally
		if( !empty( $viewData ) )
		{
			$renderedContent = $view->render( $viewData );
			
			// Store in cache using cache key data if cache should be used
			if( $shouldUseCache )
			{
				$viewCache = $this->initializeViewCache();
				if( $viewCache )
				{
					// When cache is explicitly enabled, bypass global check
					if( $cacheEnabled === true || $viewCache->isEnabled() )
					{
						$cacheKey = $viewCache->generateKey(
							$this->getControllerName(),
							$page,
							$cacheKeyData
						);
						try
						{
							// Temporarily enable cache if needed for storage
							$wasEnabled = $viewCache->isEnabled();
							if( $cacheEnabled === true && !$wasEnabled )
							{
								$viewCache->setEnabled( true );
							}
							
							$viewCache->set( $cacheKey, $renderedContent );
							
							// Restore original state
							if( $cacheEnabled === true && !$wasEnabled )
							{
								$viewCache->setEnabled( false );
							}
						}
						catch( CacheException $e )
						{
							// Silently fail on cache write errors
						}
					}
				}
			}
			
			return $renderedContent;
		}

		// No view data and no cache - this shouldn't happen in normal use
		// but render with cache key data as fallback
		return $view->render( $cacheKeyData );
	}

	/**
	 * Render Markdown with separate cache key data.
	 * Allows checking/using cache without fetching full view data.
	 * 
	 * @param HttpResponseStatus $responseCode HTTP response status
	 * @param array $viewData Full data for rendering (can be empty if using cache)
	 * @param array $cacheKeyData Minimal data for cache key generation
	 * @param string $page Page template name
	 * @param string $layout Layout template name
	 * @param bool|null $cacheEnabled Whether to enable caching
	 * @return string Rendered Markdown content
	 * @throws \Neuron\Core\Exceptions\NotFound
	 * @throws CommonMarkException
	 */
	public function renderMarkdownWithCacheKey( 
		HttpResponseStatus $responseCode, 
		array $viewData = [], 
		array $cacheKeyData = [], 
		string $page = "index", 
		string $layout = "default", 
		?bool $cacheEnabled = null 
	): string
	{
		@http_response_code( $responseCode->value );

		// Determine if cache should be used based on explicit setting or system default
		$shouldUseCache = $cacheEnabled !== false && 
		                  ( $cacheEnabled === true || $this->isCacheEnabledByDefault() );

		// If view data is empty and cache should be used, try to get cached content
		if( empty( $viewData ) && $shouldUseCache )
		{
			$cachedContent = $this->getViewCacheByKey( $page, $cacheKeyData );
			if( $cachedContent !== null )
			{
				return $cachedContent;
			}
		}

		// Create view and set up for rendering
		$view = new Markdown()
			->setController( $this->getControllerViewPath() )
			->setLayout( $layout )
			->setPage( $page )
			->setCacheEnabled( $cacheEnabled );

		// If we have view data, render normally
		if( !empty( $viewData ) )
		{
			$renderedContent = $view->render( $viewData );
			
			// Store in cache using cache key data if cache should be used
			if( $shouldUseCache )
			{
				$viewCache = $this->initializeViewCache();
				if( $viewCache )
				{
					// When cache is explicitly enabled, bypass global check
					if( $cacheEnabled === true || $viewCache->isEnabled() )
					{
						$cacheKey = $viewCache->generateKey(
							$this->getControllerName(),
							$page,
							$cacheKeyData
						);
						try
						{
							// Temporarily enable cache if needed for storage
							$wasEnabled = $viewCache->isEnabled();
							if( $cacheEnabled === true && !$wasEnabled )
							{
								$viewCache->setEnabled( true );
							}
							
							$viewCache->set( $cacheKey, $renderedContent );
							
							// Restore original state
							if( $cacheEnabled === true && !$wasEnabled )
							{
								$viewCache->setEnabled( false );
							}
						}
						catch( CacheException $e )
						{
							// Silently fail on cache write errors
						}
					}
				}
			}
			
			return $renderedContent;
		}

		// No view data and no cache - this shouldn't happen in normal use
		// but render with cache key data as fallback
		return $view->render( $cacheKeyData );
	}

	/**
	 * This method registers routes for any of the standard methods that
	 * are currently implemented in the class.
	 * Index
	 * Add
	 * Show
	 * Create
	 * Edit
	 * Update
	 * Delete
	 *
	 * @param Application $app
	 * @param string $route
	 * @throws BadRequestMethod
	 */
	public static function register( Application $app, string $route = '' ): void
	{
		if( $route == '' )
		{
			$route = strtolower( static::class );
		}

		self::registerIndex(  $app,static::class, $route );
		self::registerAdd(    $app,static::class, $route );
		self::registerShow(   $app,static::class, $route );
		self::registerCreate( $app,static::class, $route );
		self::registerEdit(   $app,static::class, $route );
		self::registerUpdate( $app,static::class, $route );
		self::registerDelete( $app,static::class, $route );
	}

	/**
	 * @param Application $app
	 * @param string $controller
	 * @param string $route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerIndex( Application $app, string $controller, string $route ): void
	{
		if( method_exists( $controller, 'index' ) )
		{
			$app->addRoute(
				"GET",
				"/$route",
				"$controller@index"
			);
		}
	}

	/**
	 * @param Application $app
	 * @param string $controller
	 * @param string $route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerAdd( Application $app, string $controller, string $route ): void
	{
		if( method_exists( $controller, 'add' ) )
		{
			$app->addRoute(
				"GET",
				"/$route/new",
				"$controller@add"
			);
		}
	}

	/**
	 * @param Application $app
	 * @param string $controller
	 * @param string $route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerShow( Application $app, string $controller, string $route ): void
	{
		if( method_exists( $controller, 'show' ) )
		{
			$app->addRoute(
				"GET",
				"/$route/:id",
				"$controller@show"
			);
		}
	}

	/**
	 * @param Application $app
	 * @param string $controller
	 * @param string $route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerCreate( Application $app, string $controller, string $route ): void
	{
		if( method_exists( $controller, 'create' ) )
		{
			$app->addRoute(
				"POST",
				"/$route/create",
				"$controller@create"
			);
		}
	}

	/**
	 * @param Application $app
	 * @param string $controller
	 * @param string $route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerEdit( Application $app, string $controller, string $route ): void
	{
		if( method_exists( $controller, 'edit' ) )
		{
			$app->addRoute(
				"GET",
				"/$route/edit/:id",
				"$controller@edit"
			);
		}
	}

	/**
	 * @param Application $app
	 * @param string $controller
	 * @param string $route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerUpdate( Application $app, string $controller, string $route ): void
	{
		if( method_exists( $controller, 'update' ) )
		{
			$app->addRoute(
				"POST",
				"/$route/:id",
				"$controller@update"
			);
		}
	}

	/**
	 * @param Application $app
	 * @param string $controller
	 * @param string $route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerDelete( Application $app, string $controller, string $route ): void
	{
		if( method_exists( $controller, 'delete' ) )
		{
			$app->addRoute(
				"GET",
				"/$route/delete/:id",
				"$controller@delete"
			);
		}
	}

	/**
	 * Generate a relative URL for a named route.
	 * 
	 * @param string $routeName The name of the route
	 * @param array $parameters Parameters to substitute in the route path
	 * @return string|null The generated relative URL or null if route not found
	 */
	protected function urlFor( string $routeName, array $parameters = [] ): ?string
	{
		if( !isset( $this->_router ) || !method_exists( $this->_router, 'generateUrl' ) )
		{
			return null;
		}
		return $this->_router->generateUrl( $routeName, $parameters, false );
	}

	/**
	 * Generate an absolute URL for a named route.
	 * 
	 * @param string $routeName The name of the route
	 * @param array $parameters Parameters to substitute in the route path
	 * @return string|null The generated absolute URL or null if route not found
	 */
	protected function urlForAbsolute( string $routeName, array $parameters = [] ): ?string
	{
		if( !isset( $this->_router ) || !method_exists( $this->_router, 'generateUrl' ) )
		{
			return null;
		}
		return $this->_router->generateUrl( $routeName, $parameters, true );
	}

	/**
	 * Create a new UrlHelper instance for use in controllers.
	 * 
	 * @return UrlHelper|null The URL helper instance or null if router not available
	 */
	protected function urlHelper(): ?UrlHelper
	{
		if( !isset( $this->_router ) )
		{
			return null;
		}
		return new UrlHelper( $this->_router );
	}

	/**
	 * Check if a named route exists.
	 * 
	 * @param string $routeName The route name to check
	 * @return bool True if route exists, false otherwise
	 */
	protected function routeExists( string $routeName ): bool
	{
		if( !isset( $this->_router ) || !method_exists( $this->_router, 'getRouteByName' ) )
		{
			return false;
		}
		return $this->_router->getRouteByName( $routeName ) !== null;
	}

	/**
	 * Magic method to provide Rails-style URL helper methods in controllers.
	 * 
	 * Supports patterns like:
	 * - `$this->userProfilePath(['id' => 123])` -> generates relative URL for 'user_profile' route
	 * - `$this->userProfileUrl(['id' => 123])` -> generates absolute URL for 'user_profile' route
	 * 
	 * @param string $method The method name (e.g., 'userProfilePath', 'userProfileUrl')
	 * @param array $arguments Method arguments, first should be parameters array
	 * @return string|null Generated URL or null if route not found
	 * @throws \BadMethodCallException If method pattern is not recognized
	 * 
	 * @example
	 * ```php
	 * // In a controller method:
	 * $profilePath = $this->userProfilePath(['id' => $userId]);
	 * $absoluteUrl = $this->userProfileUrl(['id' => $userId]);
	 * 
	 * // Redirect to a named route:
	 * return redirect($this->userDashboardPath());
	 * ```
	 */
	public function __call( string $method, array $arguments ): ?string
	{
		$parameters = $arguments[0] ?? [];

		// Handle *Path() methods for relative URLs
		if( preg_match( '/^(.+)Path$/', $method, $matches ) )
		{
			$routeName = ( new \Neuron\Core\NString( $matches[1] ) )->toSnakeCase();
			return $this->urlFor( $routeName, $parameters );
		}

		// Handle *Url() methods for absolute URLs
		if( preg_match( '/^(.+)Url$/', $method, $matches ) )
		{
			$routeName = ( new \Neuron\Core\NString( $matches[1] ) )->toSnakeCase();
			return $this->urlForAbsolute( $routeName, $parameters );
		}

		// If no URL helper pattern matches, throw an exception
		throw new \BadMethodCallException( "Method '$method' not found in " . static::class );
	}
}
