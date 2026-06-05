<?php
namespace Neuron\Mvc;

use Exception;
use Neuron\Application\Base;
use Neuron\Application\CrossCutting\Event;
use Neuron\Core\Exceptions\BadRequestMethod;
use Neuron\Core\Exceptions\Forbidden;
use Neuron\Core\Exceptions\MissingMethod;
use Neuron\Core\Exceptions\NotFound;
use Neuron\Core\Exceptions\Unauthorized;
use Neuron\Core\Exceptions\Validation;
use Neuron\Core\Registry\RegistryKeys;
use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;
use Neuron\Data\Settings\Source\ISettingSource;
use Neuron\Log\Log;
use Neuron\Mvc\Controllers\Factory;
use Neuron\Mvc\Events\Http401;
use Neuron\Mvc\Events\Http403;
use Neuron\Mvc\Events\Http404;
use Neuron\Mvc\Events\Http500;
use Neuron\Mvc\Requests\Request;
use Neuron\Patterns\Container\IContainer;
use Neuron\Patterns\Registry;
use Neuron\Routing\RequestMethod;
use Neuron\Routing\Router;
use Neuron\Routing\RouteScanner;
use Neuron\Routing\RouteDefinition;

/**
 * Class Application
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends Base implements IMvcApplication
{
	private Router $_router;
	private array $_requests = [];
	private bool $_captureOutput = false;
	private ?string $_output = '';
	private IFileSystem $fs;
	private ?IContainer $_container = null;

	/**
	 * Application constructor.
	 * @param string $version
	 * @param ISettingSource|null $source
	 * @param IFileSystem|null $fs File system implementation (null = use real file system)
	 * @throws Exception
	 */
	public function __construct( string $version ="1.0.0", ?ISettingSource $source = null, ?IFileSystem $fs = null )
	{
		$this->setHandleFatal( true );
		$this->setHandleErrors( true );
		$this->fs = $fs ?? new RealFileSystem();

		// Must call parent first to initialize settings
		parent::__construct( $version, $source );

		// Load passthrough exceptions BEFORE anything else that might throw
		// This must happen before loadRequests/loadRoutes/etc
		$passthroughExceptions = $this->getSetting( 'exceptions', 'passthrough' );
		if( is_array( $passthroughExceptions ) )
		{
			Registry::getInstance()->set( RegistryKeys::PASSTHROUGH_EXCEPTIONS_LEGACY, $passthroughExceptions );
			\Neuron\Log\Log::debug( 'Loaded passthrough exceptions: ' . json_encode( $passthroughExceptions ) );
		}
		else
		{
			// No exceptions configured, set to empty array
			Registry::getInstance()->set( RegistryKeys::PASSTHROUGH_EXCEPTIONS_LEGACY, [] );
			\Neuron\Log\Log::debug( 'No passthrough exceptions configured' );
		}

		// Populate the canonical base path key, plus the deprecated legacy key
		// for backward compatibility during the deprecation window.
		Registry::getInstance()->set( RegistryKeys::BASE_PATH, $this->getBasePath() );
		Registry::getInstance()->set( RegistryKeys::BASE_PATH_LEGACY, $this->getBasePath() );
		Registry::getInstance()->set( RegistryKeys::APP, $this );

		$this->loadRequests();
		$this->loadRoutes();
	}

	/**
	 * @param bool $captureOutput
	 * @return $this
	 */
	public function setCaptureOutput( bool $captureOutput ): Application
	{
		$this->_captureOutput = $captureOutput;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function getCaptureOutput(): bool
	{
		return $this->_captureOutput;
	}

	/**
	 * @return string
	 */
	public function getOutput(): ?string
	{
		return $this->_output;
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	protected function loadRequests(): void
	{
		$requestPath = $this->getBasePath().'/config/requests';

		if( $this->getRegistryObject( RegistryKeys::REQUESTS_PATH ) )
		{
			$requestPath = $this->getRegistryObject( RegistryKeys::REQUESTS_PATH );
		}

		$files = $this->fs->glob($requestPath . '/*.yaml');

		if( $files === false )
		{
			return;
		}

		foreach( $files as $filename )
		{
			$name = pathinfo( $filename )['filename'];

			$request = new Request();
			$request->loadFile( $filename );

			$this->_requests[ $name ] = $request;
		}
	}

	/**
	 * @param string $method
	 * @param string $route
	 * @param string $controllerMethod
	 * @param string $request
	 * @param string|array $filters
	 * @return \Neuron\Routing\RouteMap
	 *
	 * @throws BadRequestMethod
	 * @throws Exception
	 */
	public function addRoute( string $method, string $route, string $controllerMethod, string $request = '', string|array $filters = '', ?string $name = null ) : \Neuron\Routing\RouteMap
	{
		switch( RequestMethod::getType( $method ) )
		{
			case RequestMethod::PUT:
				$routeMap = $this->_router->put(
					$route,
					function( $parameters ) use ( $request )
					{
						return $this->executeController( $parameters, $request );
					},
					$filters,
					$name
				);

				break;

			case RequestMethod::GET:
				$routeMap = $this->_router->get(
					$route,
					function( $parameters ) use ( $request )
					{
						return $this->executeController( $parameters, $request );
					},
					$filters,
					$name
				);
				break;

			case RequestMethod::POST:
				$routeMap = $this->_router->post(
					$route,
					function( $parameters ) use ( $request )
					{
						return $this->executeController( $parameters, $request );
					},
					$filters,
					$name
				);
				break;

			case RequestMethod::DELETE:
				$routeMap = $this->_router->delete(
					$route,
					function( $parameters ) use ( $request )
					{
						return $this->executeController( $parameters, $request );
					},
					$filters,
					$name
				);
				break;

			case RequestMethod::UNKNOWN:
				throw new BadRequestMethod();
		}

		$routeMap->Payload = [ "Controller" => $controllerMethod ];

		return $routeMap;
	}

	/**
	 * @return Router
	 */
	public function getRouter() : Router
	{
		return $this->_router;
	}

	/**
	 * @return bool
	 * @throws Exception
	 */
	protected function onStart(): bool
	{
		$viewPath = $this->getSetting( 'views', 'path' );
		$basePath = $this->getBasePath();

		if( $viewPath )
			Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, $basePath.'/'.$viewPath );

		return parent::onStart();
	}

	/**
	 * @return void
	 * @throws Exception
	 * @throws MissingMethod
	 * @throws BadRequestMethod
	 * @throws NotFound
	 */
	protected function onRun() : void
	{
		// Emit request received event
		$params = $this->getParameters();
		$method = $params['REQUEST_METHOD'] ?? 'GET';
		$route = $params['REQUEST_URI'] ?? '/';
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

		Event::emit( new Events\RequestReceivedEvent(
			$method,
			$route,
			$ip,
			microtime( true )
		) );

		$output = $this->_router->run( $this->getParameters() );

		if( !$this->_captureOutput )
		{
			echo $output;
		}
		else
		{
			$this->_output = $output;
		}
	}

	/**
	 * This method is called by the route lambdas and handles
	 * instantiating the required controller and calling the correct method.
	 *
	 * @param array $parameters
	 * @param string $requestName
	 * @return mixed
	 * @throws MissingMethod
	 * @throws Exception
	 */
	public function executeController( array $parameters, string $requestName = '' ): mixed
	{
		$parts = explode( '@', $parameters[ "Controller" ] );

		$controller = $parts[ 0 ];
		$method     = $parts[ 1 ];

		// Check if we're already handling an error to prevent infinite recursion
		$isErrorHandler = in_array( $controller, [
			'Neuron\Mvc\Controllers\HttpCodes',
			'Neuron\\Mvc\\Controllers\\HttpCodes'
		] );

		try
		{
			// Use container if available for dependency injection, otherwise fallback to Factory
			if( $this->hasContainer() )
			{
				$controller = $this->getContainer()->make( $controller );
			}
			else
			{
				$controller = Factory::create( $this, $controller );
			}

			if( !method_exists( $controller, $method ) )
			{
				throw new MissingMethod( "Method '$method'' not found." );
			}

			if( empty( $requestName ) )
			{
				$request = new Request();
			}
			else
			{
				$request = $this->getRequest( $requestName );

				try
				{
					$request->processPayload( $request->getJsonPayload() );
				}
				catch( Exception $e )
				{
					Log::error( $e->getMessage() );
				}
			}

			$request->setRouteParameters( $parameters );

			return $controller->$method( $request );
		}
		catch( Unauthorized $e )
		{
			// If we're already in error handler, re-throw to avoid recursion
			if( $isErrorHandler )
			{
				throw $e;
			}

			Log::warning( "Authentication required: " . $e->getMessage() );

			Event::emit( new Http401(
				$parameters['route'] ?? 'unknown',
				$e->getRealm()
			) );

			return $this->executeController(
				array_merge(
					$parameters,
					[
						"Controller" => "Neuron\Mvc\Controllers\HttpCodes@code401",
						"realm" => $e->getRealm()
					]
				)
			);
		}
		catch( Forbidden $e )
		{
			// If we're already in error handler, re-throw to avoid recursion
			if( $isErrorHandler )
			{
				throw $e;
			}

			Log::warning( "Access forbidden: " . $e->getMessage() );

			Event::emit( new Http403(
				$parameters['route'] ?? 'unknown',
				$e->getResource(),
				$e->getPermission()
			) );

			return $this->executeController(
				array_merge(
					$parameters,
					[
						"Controller" => "Neuron\Mvc\Controllers\HttpCodes@code403",
						"resource" => $e->getResource(),
						"permission" => $e->getPermission()
					]
				)
			);
		}
		catch( NotFound $e )
		{
			// If we're already in error handler, re-throw to avoid recursion
			if( $isErrorHandler )
			{
				throw $e;
			}

			Log::warning( "Resource not found: " . $e->getMessage() );

			Event::emit( new Http404( $parameters['route'] ?? 'unknown' ) );

			return $this->executeController(
				array_merge(
					$parameters,
					[
						"Controller" => "Neuron\Mvc\Controllers\HttpCodes@code404",
					]
				)
			);
		}
		catch( \Throwable $e )
		{
			// Check if this exception should pass through to application-level handlers
			// Applications can configure exception classes via neuron.yaml under 'exceptions.passthrough'
			$passthroughExceptions = Registry::getInstance()->get( RegistryKeys::PASSTHROUGH_EXCEPTIONS_LEGACY ) ?? [];

			if( in_array( get_class( $e ), $passthroughExceptions ) )
			{
				throw $e;
			}

			Log::error( "Exception in controller: " . $e->getMessage() );

			Event::emit(
				new Http500(
					$parameters['route'] ?? 'unknown',
					get_class( $e ),
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);

			$this->onCrash(
				[
					'type' => get_class( $e ),
					'message' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine()
				]
			);

			return $this->executeController(
				array_merge(
					$parameters,
					[
						"Controller" => "Neuron\Mvc\Controllers\HttpCodes@code500",
						"error" => $e->getMessage(),
						"type" => get_class( $e )
					]
				)
			);
		}
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	protected function loadRoutes(): void
	{
		$this->_router = new Router();

		// Configure rate limiting if enabled
		$this->configureRateLimit();

		// Configure framework-level CSRF protection (enabled by default)
		$this->configureCsrf();

		$this->configure404Route();

		// Load routing configuration (rewrites, controller paths)
		$this->loadRoutingConfig();

		// Load routes from controller attributes
		$this->loadAttributeRoutes();
	}

	/**
	 * Load routing configuration from routing.yaml file.
	 *
	 * This method loads URL rewrites and controller paths from the routing
	 * configuration file. If routing.yaml doesn't exist, it falls back to
	 * reading controller_paths from neuron.yaml for backward compatibility.
	 *
	 * @return void
	 * @throws Exception
	 */
	protected function loadRoutingConfig(): void
	{
		$basePath = $this->getBasePath();
		$routingFile = $basePath . '/config/routing.yaml';

		// Check if routing.yaml exists
		if( !$this->fs->fileExists( $routingFile ) )
		{
			Log::debug( "No routing.yaml found, using controller_paths from neuron.yaml" );
			return;
		}

		// Load and parse routing.yaml
		$content = $this->fs->readFile( $routingFile );

		if( $content === false )
		{
			Log::warning( "Failed to read routing.yaml" );
			return;
		}

		try
		{
			$config = \Symfony\Component\Yaml\Yaml::parse( $content );

			// Configure URL rewrites
			if( isset( $config['rewrites'] ) && is_array( $config['rewrites'] ) )
			{
				$this->_router->setUrlRewrites( $config['rewrites'] );
				Log::debug( "Loaded " . count( $config['rewrites'] ) . " URL rewrite(s)" );
			}

			// Store controller paths in registry for loadAttributeRoutes()
			// If routing.yaml exists, it takes precedence even if controller_paths is not defined
			if( isset( $config['controller_paths'] ) && is_array( $config['controller_paths'] ) )
			{
				Registry::getInstance()->set( RegistryKeys::ROUTING_CONTROLLER_PATHS, $config['controller_paths'] );
			}
			else
			{
				// routing.yaml exists but doesn't define controller_paths
				// Set to empty array to prevent fallback to neuron.yaml
				Registry::getInstance()->set( RegistryKeys::ROUTING_CONTROLLER_PATHS, [] );
				Log::debug( "routing.yaml exists but has no controller_paths defined (no fallback to neuron.yaml)" );
			}
		}
		catch( \Symfony\Component\Yaml\Exception\ParseException $e )
		{
			Log::error( "Failed to parse routing.yaml: " . $e->getMessage() );
		}
	}

	/**
	 * Load routes from controller attributes
	 *
	 * @return void
	 * @throws Exception
	 */
	protected function loadAttributeRoutes(): void
	{
		// Try to get controller paths from routing.yaml (via Registry)
		$controllerPaths = Registry::getInstance()->get( RegistryKeys::ROUTING_CONTROLLER_PATHS );

		// Fall back to neuron.yaml for backward compatibility
		// Use === null to distinguish between "not set" and "explicitly empty array"
		if( $controllerPaths === null )
		{
			$controllerPaths = $this->getSetting( 'routing', 'controller_paths' );
		}

		// If still null or not an array, no controller paths configured
		if( $controllerPaths === null || !is_array( $controllerPaths ) )
		{
			Log::debug( "No controller_paths configured in routing settings" );
			return;
		}

		// If explicitly set to empty array, respect that (don't scan any paths)
		if( count( $controllerPaths ) === 0 )
		{
			Log::debug( "controller_paths explicitly set to empty array, skipping route scanning" );
			return;
		}

		$scanner = new RouteScanner();
		$basePath = $this->getBasePath();

		foreach( $controllerPaths as $pathConfig )
		{
			$directory = $basePath . '/' . $pathConfig['path'];
			$namespace = $pathConfig['namespace'];

			if( !is_dir( $directory ) )
			{
				Log::debug( "Controller directory not found: $directory" );
				continue;
			}

			try
			{
				$routeDefinitions = $scanner->scanDirectory( $directory, $namespace );

				foreach( $routeDefinitions as $def )
				{
					$this->registerAttributeRoute( $def );
				}
			}
			catch( \Exception $e )
			{
				Log::error( "Failed to scan directory $directory: " . $e->getMessage() );
			}
		}
	}

	/**
	 * Register a single route from an attribute definition
	 *
	 * @param RouteDefinition $def
	 * @return void
	 * @throws Exception
	 */
	protected function registerAttributeRoute( RouteDefinition $def ): void
	{
		$this->addRoute(
			$def->method,
			$def->path,
			$def->getControllerMethod(),
			'', // No request validation for attribute routes
			$def->filters,
			$def->name // Pass name directly for duplicate detection
		);
	}

	/**
	 * @param string $name
	 * @return ?Request
	 * @throws Exception
	 */
	public function getRequest( string $name ): ?Request
	{
		if( empty( $name ) )
		{
			return null;
		}

		if( !isset( $this->_requests[ $name ] ) )
		{
			throw new Exception( "Request not found: $name" );
		}

		return $this->_requests[ $name ];
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	protected function configure404Route(): void
	{
		$this->_router->get( "/404",
			function( $parameters )
			{
				Event::emit( new Http404( $parameters[ "route" ] ) );

				return self::executeController(
					array_merge(
						$parameters,
						[
							"Controller" => "Neuron\Mvc\Controllers\HttpCodes@code404",
						]
					)
				);
			}
		);
	}

	/**
	 * Configure rate limiting if enabled in settings.
	 *
	 * @return void
	 */
	protected function configureRateLimit(): void
	{
		$source = $this->getSettingManager()?->getSource();

		if( !$source )
		{
			return;
		}

		// Check if rate limiting extension is available
		if( !class_exists( '\Neuron\Routing\RateLimit\RateLimitConfig' ) )
		{
			return;
		}

		// Configure standard rate_limit
		if( $source->get( 'rate_limit', 'enabled' ) )
		{
			try
			{
				$config = \Neuron\Routing\RateLimit\RateLimitConfig::fromSettings( $source, 'rate_limit' );
				$filter = new \Neuron\Routing\Filters\RateLimitFilter( $config );
				$this->_router->registerFilter( 'rate_limit', $filter );

				// Apply globally if configured
				if( $source->get( 'rate_limit', 'global' ) )
				{
					$this->_router->addFilter( 'rate_limit' );
				}

				Log::debug( 'Rate limiting configured: rate_limit' );
			}
			catch( \Exception $e )
			{
				Log::warning( 'Failed to configure rate limiting: ' . $e->getMessage() );
			}
		}

		// Configure api_limit
		if( $source->get( 'api_limit', 'enabled' ) )
		{
			try
			{
				$config = \Neuron\Routing\RateLimit\RateLimitConfig::fromSettings( $source, 'api_limit' );
				$filter = new \Neuron\Routing\Filters\RateLimitFilter( $config );
				$this->_router->registerFilter( 'api_limit', $filter );

				Log::debug( 'Rate limiting configured: api_limit' );
			}
			catch( \Exception $e )
			{
				Log::warning( 'Failed to configure API rate limiting: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Register framework-level CSRF protection.
	 *
	 * Registers the 'csrf' route filter so any route can opt in via
	 * `filters: ['csrf']`, and seeds the current token into the registry so
	 * the csrf_field() / csrf_token() view helpers work with no per-app wiring.
	 *
	 * Enabled by default; disable by setting `security.csrf` to false.
	 *
	 * @return void
	 */
	protected function configureCsrf(): void
	{
		$source = $this->getSettingManager()?->getSource();
		$enabled = $source?->get( 'security', 'csrf' );

		// Default on: only skip when explicitly disabled.
		if( in_array( $enabled, [ false, 0, '0', 'false', 'off', 'no' ], true ) )
		{
			return;
		}

		try
		{
			$csrfToken = new \Neuron\Mvc\Security\CsrfToken( new \Neuron\Core\System\RealSession() );
			$this->_router->registerFilter( 'csrf', new \Neuron\Mvc\Security\CsrfFilter( $csrfToken ) );

			// Seed the token for views. Avoid starting a session in CLI/test
			// contexts or once output has begun.
			if( PHP_SAPI !== 'cli' && !headers_sent() )
			{
				Registry::getInstance()->set( RegistryKeys::AUTH_CSRF_TOKEN, $csrfToken->getToken() );
			}

			Log::debug( 'CSRF protection configured' );
		}
		catch( \Exception $e )
		{
			Log::warning( 'Failed to configure CSRF: ' . $e->getMessage() );
		}
	}

	/**
	 * Clear expired cache entries
	 *
	 * @return int Number of entries removed
	 */
	public function clearExpiredCache(): int
	{
		$cache = Registry::getInstance()->get( RegistryKeys::VIEW_CACHE_LEGACY );

		if( $cache instanceof \Neuron\Mvc\Cache\ViewCache )
		{
			return $cache->gc();
		}

		// Try to initialize cache from settings if not already loaded
		$settings = $this->getSettingManager();

		if( $settings )
		{
			try
			{
				$config = \Neuron\Mvc\Cache\CacheConfig::fromSettings( $settings->getSource() );

				if( $config->isEnabled() )
				{
					$basePath = $this->getBasePath();

					$storage = \Neuron\Mvc\Cache\Storage\CacheStorageFactory::createFromConfig( $config, $basePath );

					return $storage->gc();
				}
			}
			catch( \Exception $e )
			{
				// Unable to initialize cache
			}
		}

		return 0;
	}

	public static function beautifyException( \Throwable $e ): string
	{
		// this function should return a nicely formatted HTML representation of the exception
		$exceptionType = get_class( $e );
		$message = htmlspecialchars( $e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$file = htmlspecialchars( $e->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$line = $e->getLine();
		$trace = nl2br( htmlspecialchars( $e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
		$html = "<html><head><title>Exception: $exceptionType</title>
		<style>
		body { font-family: Arial, sans-serif; margin: 20px; }
		h1 { color: #c00; }
		pre { background-color: #f4f4f4; padding: 10px; border: 1px solid #ddd; }
		</style>
		</head><body>";
		$html .= "<h1>Exception: $exceptionType</h1>";
		$html .= "<p><strong>Message:</strong> $message</p>";
		$html .= "<p><strong>File:</strong> $file</p>";
		$html .= "<p><strong>Line:</strong> $line</p>";
		$html .= "<h2>Stack Trace:</h2><pre>$trace</pre>";
		$html .= "</body></html>";

		return $html;
	}

	public function handleException( \Throwable $e ) : string
	{
		if( $this->getCaptureOutput() )
		{
			$this->_output .= self::beautifyException( $e );
			return $this->_output;
		}
		else
		{
			return self::beautifyException( $e );
		}
	}

	/**
	 * Set the dependency injection container
	 *
	 * @param IContainer $container
	 * @return void
	 */
	public function setContainer( IContainer $container ): void
	{
		$this->_container = $container;
	}

	/**
	 * Get the dependency injection container
	 *
	 * @return IContainer
	 * @throws Exception If container has not been set
	 */
	public function getContainer(): IContainer
	{
		if( $this->_container === null )
		{
			throw new Exception( 'Container has not been set on Application' );
		}

		return $this->_container;
	}

	/**
	 * Check if container has been set
	 *
	 * @return bool
	 */
	public function hasContainer(): bool
	{
		return $this->_container !== null;
	}
}

