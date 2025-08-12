<?php

namespace Neuron\Mvc\Controllers;

use League\CommonMark\Exception\CommonMarkException;
use Neuron\Core\Exceptions\BadRequestMethod;
use Neuron\Data\Setting\SettingManager;
use Neuron\Mvc\Application;
use Neuron\Mvc\Cache\CacheConfig;
use Neuron\Mvc\Cache\Exceptions\CacheException;
use Neuron\Mvc\Cache\Storage\FileCacheStorage;
use Neuron\Mvc\Cache\ViewCache;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Mvc\Views\Html;
use Neuron\Mvc\Views\Json;
use Neuron\Mvc\Views\Markdown;
use Neuron\Mvc\Views\NotFound;
use Neuron\Mvc\Views\Xml;
use Neuron\Routing\Router;

class Base implements IController
{
	private Router  $_Router;

	/**
	 * @param Router $Router
	 */
	public function __construct( Router $Router )
	{
		$this->setRouter( $Router );
	}

	/**
	 * @param HttpResponseStatus $ResponseCode
	 * @param array $Data
	 * @param string $Page
	 * @param string $Layout
	 * @param bool|null $CacheEnabled
	 * @return string
	 * @throws CommonMarkException
	 * @throws \Neuron\Core\Exceptions\NotFound
	 */
	public function renderMarkdown( HttpResponseStatus $ResponseCode, array $Data = [], string $Page = "index", string $Layout = "default", ?bool $CacheEnabled = null ) : string
	{
		@http_response_code( $ResponseCode->value );

		$View = new Markdown()
			->setController( new \ReflectionClass( static::class )->getShortName() )
			->setLayout( $Layout )
			->setPage( $Page )
			->setCacheEnabled( $CacheEnabled );

		return $View->render( $Data );
	}

	/**
	 * @param HttpResponseStatus $ResponseCode
	 * @param array $Data
	 * @param string $Page
	 * @param string $Layout
	 * @param bool|null $CacheEnabled
	 * @return string
	 * @throws \Neuron\Core\Exceptions\NotFound
	 */
	public function renderHtml( HttpResponseStatus $ResponseCode, array $Data = [], string $Page = "index", string $Layout = "default", ?bool $CacheEnabled = null ) : string
	{
		@http_response_code( $ResponseCode->value );

		$View = new Html()
			->setController( new \ReflectionClass( static::class )->getShortName() )
			->setLayout( $Layout )
			->setPage( $Page )
			->setCacheEnabled( $CacheEnabled );

		return $View->render( $Data );
	}

	/**
	 * @param HttpResponseStatus $ResponseCode
	 * @param array $Data
	 * @return string
	 */
	public function renderJson( HttpResponseStatus $ResponseCode, array $Data = [] ): string
	{
		@http_response_code( $ResponseCode->value );

		$View = new Json();

		return $View->render( $Data );
	}

	/**
	 * @param HttpResponseStatus $ResponseCode
	 * @param array $Data
	 * @return string
	 */
	public function renderXml( HttpResponseStatus $ResponseCode, array $Data = [] ): string
	{
		@http_response_code( $ResponseCode->value );

		$View = new Xml();

		return $View->render( $Data );
	}

	/**
	 * @return Router
	 */
	public function getRouter(): Router
	{
		return $this->_Router;
	}

	/**
	 * @param Router $Router
	 * @return Base
	 */
	public function setRouter( Router $Router ): Base
	{
		$this->_Router = $Router;
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
	 * Initialize ViewCache if not already present in Registry.
	 * This allows controllers to check cache before making expensive API calls.
	 * 
	 * @return ViewCache|null The ViewCache instance or null if initialization fails
	 */
	protected function initializeViewCache(): ?ViewCache
	{
		$Registry = \Neuron\Patterns\Registry::getInstance();
		
		// Check if cache is already initialized
		$ViewCache = $Registry->get( 'ViewCache' );
		if( $ViewCache !== null )
		{
			return $ViewCache;
		}
		
		// Try to create cache from settings
		$Settings = $Registry->get( 'Settings' );
		if( $Settings === null )
		{
			return null;
		}
		
		// Handle both SettingManager and ISettingSource
		if( $Settings instanceof SettingManager )
		{
			$SettingSource = $Settings->getSource();
		}
		else
		{
			$SettingSource = $Settings;
		}
		
		$Config = CacheConfig::fromSettings( $SettingSource );
		
		if( !$Config->isEnabled() )
		{
			return null;
		}
		
		try
		{
			$BasePath = $Registry->get( 'Base.Path' ) ?? '.';
			$CachePath = $BasePath . DIRECTORY_SEPARATOR . $Config->getCachePath();
			
			$Storage = new FileCacheStorage( $CachePath );
			$ViewCache = new ViewCache(
				$Storage, 
				true, 
				$Config->getDefaultTtl(), 
				$Config 
			);
			
			$Registry->set( 'ViewCache', $ViewCache );
			
			return $ViewCache;
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
	 * @param string $Page The page/view name
	 * @param array $Data The data that affects cache key generation
	 * @return bool True if cache exists, false otherwise
	 */
	protected function hasViewCache( string $Page, array $Data = [] ): bool
	{
		$ViewCache = $this->initializeViewCache();
		
		if( !$ViewCache || !$ViewCache->isEnabled() )
		{
			return false;
		}
		
		$CacheKey = $ViewCache->generateKey(
			$this->getControllerName(),
			$Page,
			$Data
		);
		
		return $ViewCache->exists( $CacheKey );
	}

	/**
	 * Get cached view content if available.
	 * Initializes ViewCache if needed.
	 * 
	 * @param string $Page The page/view name
	 * @param array $Data The data that affects cache key generation
	 * @return string|null The cached content or null if not found
	 */
	protected function getViewCache( string $Page, array $Data = [] ): ?string
	{
		$ViewCache = $this->initializeViewCache();
		
		if( !$ViewCache || !$ViewCache->isEnabled() )
		{
			return null;
		}
		
		$CacheKey = $ViewCache->generateKey(
			$this->getControllerName(),
			$Page,
			$Data
		);
		
		return $ViewCache->get( $CacheKey );
	}

	/**
	 * Check if view cache exists using only cache key data.
	 * This allows checking cache without fetching full view data.
	 * 
	 * @param string $Page The page/view name
	 * @param array $CacheKeyData The minimal data that determines cache uniqueness
	 * @return bool True if cache exists, false otherwise
	 */
	protected function hasViewCacheByKey( string $Page, array $CacheKeyData = [] ): bool
	{
		$ViewCache = $this->initializeViewCache();
		
		if( !$ViewCache || !$ViewCache->isEnabled() )
		{
			return false;
		}
		
		$CacheKey = $ViewCache->generateKey(
			$this->getControllerName(),
			$Page,
			$CacheKeyData
		);
		
		return $ViewCache->exists( $CacheKey );
	}

	/**
	 * Get cached view content using only cache key data.
	 * This allows retrieving cache without fetching full view data.
	 * 
	 * @param string $Page The page/view name
	 * @param array $CacheKeyData The minimal data that determines cache uniqueness
	 * @return string|null The cached content or null if not found
	 */
	protected function getViewCacheByKey( string $Page, array $CacheKeyData = [] ): ?string
	{
		$ViewCache = $this->initializeViewCache();
		
		if( !$ViewCache || !$ViewCache->isEnabled() )
		{
			return null;
		}
		
		$CacheKey = $ViewCache->generateKey(
			$this->getControllerName(),
			$Page,
			$CacheKeyData
		);
		
		return $ViewCache->get( $CacheKey );
	}

	/**
	 * Render HTML with separate cache key data.
	 * Allows checking/using cache without fetching full view data.
	 * 
	 * @param HttpResponseStatus $ResponseCode HTTP response status
	 * @param array $ViewData Full data for rendering (can be empty if using cache)
	 * @param array $CacheKeyData Minimal data for cache key generation
	 * @param string $Page Page template name
	 * @param string $Layout Layout template name
	 * @param bool|null $CacheEnabled Whether to enable caching
	 * @return string Rendered HTML content
	 * @throws \Neuron\Core\Exceptions\NotFound
	 */
	public function renderHtmlWithCacheKey( 
		HttpResponseStatus $ResponseCode, 
		array $ViewData = [], 
		array $CacheKeyData = [], 
		string $Page = "index", 
		string $Layout = "default", 
		?bool $CacheEnabled = null 
	): string
	{
		@http_response_code( $ResponseCode->value );

		// If view data is empty and cache is enabled, try to get cached content
		if( empty( $ViewData ) && $CacheEnabled !== false )
		{
			$CachedContent = $this->getViewCacheByKey( $Page, $CacheKeyData );
			if( $CachedContent !== null )
			{
				return $CachedContent;
			}
		}

		// Create view and set up for rendering
		$View = new Html()
			->setController( $this->getControllerName() )
			->setLayout( $Layout )
			->setPage( $Page )
			->setCacheEnabled( $CacheEnabled );

		// If we have view data, render normally
		if( !empty( $ViewData ) )
		{
			$RenderedContent = $View->render( $ViewData );
			
			// Store in cache using cache key data
			if( $CacheEnabled === true )
			{
				$ViewCache = $this->initializeViewCache();
				if( $ViewCache && $ViewCache->isEnabled() )
				{
					$CacheKey = $ViewCache->generateKey(
						$this->getControllerName(),
						$Page,
						$CacheKeyData
					);
					try
					{
						$ViewCache->set( $CacheKey, $RenderedContent );
					}
					catch( CacheException $e )
					{
						// Silently fail on cache write errors
					}
				}
			}
			
			return $RenderedContent;
		}

		// No view data and no cache - this shouldn't happen in normal use
		// but render with cache key data as fallback
		return $View->render( $CacheKeyData );
	}

	/**
	 * Render Markdown with separate cache key data.
	 * Allows checking/using cache without fetching full view data.
	 * 
	 * @param HttpResponseStatus $ResponseCode HTTP response status
	 * @param array $ViewData Full data for rendering (can be empty if using cache)
	 * @param array $CacheKeyData Minimal data for cache key generation
	 * @param string $Page Page template name
	 * @param string $Layout Layout template name
	 * @param bool|null $CacheEnabled Whether to enable caching
	 * @return string Rendered Markdown content
	 * @throws \Neuron\Core\Exceptions\NotFound
	 * @throws CommonMarkException
	 */
	public function renderMarkdownWithCacheKey( 
		HttpResponseStatus $ResponseCode, 
		array $ViewData = [], 
		array $CacheKeyData = [], 
		string $Page = "index", 
		string $Layout = "default", 
		?bool $CacheEnabled = null 
	): string
	{
		@http_response_code( $ResponseCode->value );

		// If view data is empty and cache is enabled, try to get cached content
		if( empty( $ViewData ) && $CacheEnabled !== false )
		{
			$CachedContent = $this->getViewCacheByKey( $Page, $CacheKeyData );
			if( $CachedContent !== null )
			{
				return $CachedContent;
			}
		}

		// Create view and set up for rendering
		$View = new Markdown()
			->setController( $this->getControllerName() )
			->setLayout( $Layout )
			->setPage( $Page )
			->setCacheEnabled( $CacheEnabled );

		// If we have view data, render normally
		if( !empty( $ViewData ) )
		{
			$RenderedContent = $View->render( $ViewData );
			
			// Store in cache using cache key data
			if( $CacheEnabled === true )
			{
				$ViewCache = $this->initializeViewCache();
				if( $ViewCache && $ViewCache->isEnabled() )
				{
					$CacheKey = $ViewCache->generateKey(
						$this->getControllerName(),
						$Page,
						$CacheKeyData
					);
					try
					{
						$ViewCache->set( $CacheKey, $RenderedContent );
					}
					catch( CacheException $e )
					{
						// Silently fail on cache write errors
					}
				}
			}
			
			return $RenderedContent;
		}

		// No view data and no cache - this shouldn't happen in normal use
		// but render with cache key data as fallback
		return $View->render( $CacheKeyData );
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
	 * @param Application $App
	 * @param string $Route
	 * @throws BadRequestMethod
	 */
	public static function register( Application $App, string $Route = '' ): void
	{
		if( $Route == '' )
		{
			$Route = strtolower( static::class );
		}

		self::registerIndex(  $App,static::class, $Route );
		self::registerAdd(    $App,static::class, $Route );
		self::registerShow(   $App,static::class, $Route );
		self::registerCreate( $App,static::class, $Route );
		self::registerEdit(   $App,static::class, $Route );
		self::registerUpdate( $App,static::class, $Route );
		self::registerDelete( $App,static::class, $Route );
	}

	/**
	 * @param Application $App
	 * @param string $Controller
	 * @param string $Route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerIndex( Application $App, string $Controller, string $Route ): void
	{
		if( method_exists( $Controller, 'index' ) )
		{
			$App->addRoute(
				"GET",
				"/$Route",
				"$Controller@index"
			);
		}
	}

	/**
	 * @param Application $App
	 * @param string $Controller
	 * @param string $Route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerAdd( Application $App, string $Controller, string $Route ): void
	{
		if( method_exists( $Controller, 'add' ) )
		{
			$App->addRoute(
				"GET",
				"/$Route/new",
				"$Controller@add"
			);
		}
	}

	/**
	 * @param Application $App
	 * @param string $Controller
	 * @param string $Route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerShow( Application $App, string $Controller, string $Route ): void
	{
		if( method_exists( $Controller, 'show' ) )
		{
			$App->addRoute(
				"GET",
				"/$Route/:id",
				"$Controller@show"
			);
		}
	}

	/**
	 * @param Application $App
	 * @param string $Controller
	 * @param string $Route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerCreate( Application $App, string $Controller, string $Route ): void
	{
		if( method_exists( $Controller, 'create' ) )
		{
			$App->addRoute(
				"POST",
				"/$Route/create",
				"$Controller@create"
			);
		}
	}

	/**
	 * @param Application $App
	 * @param string $Controller
	 * @param string $Route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerEdit( Application $App, string $Controller, string $Route ): void
	{
		if( method_exists( $Controller, 'edit' ) )
		{
			$App->addRoute(
				"GET",
				"/$Route/edit/:id",
				"$Controller@edit"
			);
		}
	}

	/**
	 * @param Application $App
	 * @param string $Controller
	 * @param string $Route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerUpdate( Application $App, string $Controller, string $Route ): void
	{
		if( method_exists( $Controller, 'update' ) )
		{
			$App->addRoute(
				"POST",
				"/$Route/:id",
				"$Controller@update"
			);
		}
	}

	/**
	 * @param Application $App
	 * @param string $Controller
	 * @param string $Route
	 * @return void
	 * @throws BadRequestMethod
	 */
	protected static function registerDelete( Application $App, string $Controller, string $Route ): void
	{
		if( method_exists( $Controller, 'delete' ) )
		{
			$App->addRoute(
				"GET",
				"/$Route/delete/:id",
				"$Controller@delete"
			);
		}
	}
}
