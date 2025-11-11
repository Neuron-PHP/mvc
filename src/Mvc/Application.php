<?php
namespace Neuron\Mvc;

use Exception;
use Neuron\Application\Base;
use Neuron\Application\CrossCutting\Event;
use Neuron\Core\Exceptions\BadRequestMethod;
use Neuron\Core\Exceptions\MissingMethod;
use Neuron\Core\Exceptions\NotFound;
use Neuron\Core\Exceptions\Validation;
use Neuron\Data\Setting\Source\ISettingSource;
use Neuron\Log\Log;
use Neuron\Mvc\Controllers\Factory;
use Neuron\Mvc\Events\Http404;
use Neuron\Mvc\Requests\Request;
use Neuron\Patterns\Registry;
use Neuron\Routing\RequestMethod;
use Neuron\Routing\Router;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Application
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends Base
{
	private string $_RoutesPath;
	private Router $_Router;
	private array $_Requests = [];
	private bool $_CaptureOutput = false;
	private ?string $_Output = '';

	/**
	 * Application constructor.
	 * @param string $Version
	 * @param ISettingSource|null $Source
	 * @throws Exception
	 */
	public function __construct( string $Version ="1.0.0", ?ISettingSource $Source = null )
	{
		$this->setHandleFatal( true );
		$this->setHandleErrors( true );

		parent::__construct( $Version, $Source );

		$this->_RoutesPath = '';

		Registry::getInstance()->set( 'BasePath', $this->getBasePath() );
		Registry::getInstance()->set( 'App', $this );

		$RoutesPath = $this->getSetting( 'system', 'routes_path' );
		if( $RoutesPath )
		{
			$this->setRoutesPath( $RoutesPath );
		}

		$this->loadRequests();
		$this->loadRoutes();
	}

	/**
	 * @param bool $CaptureOutput
	 * @return $this
	 */
	public function setCaptureOutput( bool $CaptureOutput ): Application
	{
		$this->_CaptureOutput = $CaptureOutput;
		return $this;
	}

	/**
	 * @return bool
	 */
	public function getCaptureOutput(): bool
	{
		return $this->_CaptureOutput;
	}

	/**
	 * @return string
	 */
	public function getOutput(): ?string
	{
		return $this->_Output;
	}

	/**
	 * @return string
	 */
	public function getRoutesPath(): string
	{
		return $this->_RoutesPath;
	}

	/**
	 * @param string $RoutesPath
	 * @return Application
	 */
	public function setRoutesPath( string $RoutesPath ): Application
	{
		$this->_RoutesPath = $RoutesPath;
		return $this;
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	protected function loadRequests(): void
	{
		$RequestPath = $this->getBasePath().'/config/requests';

		if( $this->getRegistryObject( 'Requests.Path' ) )
		{
			$RequestPath = $this->getRegistryObject( 'Requests.Path' );
		}

		foreach( glob($RequestPath . '/*.yaml') as $filename )
		{
			$Name = pathinfo( $filename )['filename'];

			$Request = new Request();
			$Request->loadFile( $filename );

			$this->_Requests[ $Name ] = $Request;
		}
	}

	/**
	 * @param string $Method
	 * @param string $Route
	 * @param string $ControllerMethod
	 * @param string $Request
	 * @param string $Filter
	 * @return \Neuron\Routing\RouteMap
	 *
	 * @throws BadRequestMethod
	 * @throws Exception
	 */
	public function addRoute( string $Method, string $Route, string $ControllerMethod, string $Request = '', string $Filter = '' ) : \Neuron\Routing\RouteMap
	{
		switch( RequestMethod::getType( $Method ) )
		{
			case RequestMethod::PUT:
				$RouteMap = $this->_Router->put(
					$Route,
					function( $Parameters ) use ( $Request )
					{
						return $this->executeController( $Parameters, $Request );
					},
					$Filter
				);

				break;

			case RequestMethod::GET:
				$RouteMap = $this->_Router->get(
					$Route,
					function( $Parameters ) use ( $Request )
					{
						return $this->executeController( $Parameters, $Request );
					},
					$Filter
				);
				break;

			case RequestMethod::POST:
				$RouteMap = $this->_Router->post(
					$Route,
					function( $Parameters ) use ( $Request )
					{
						return $this->executeController( $Parameters, $Request );
					},
					$Filter
				);
				break;

			case RequestMethod::DELETE:
				$RouteMap = $this->_Router->delete(
					$Route,
					function( $Parameters ) use ( $Request )
					{
						return $this->executeController( $Parameters, $Request );
					},
					$Filter
				);
				break;

			case RequestMethod::UNKNOWN:
				throw new BadRequestMethod();
		}

		$RouteMap->Payload = [ "Controller" => $ControllerMethod ];

		return $RouteMap;
	}

	/**
	 * @return Router
	 */
	public function getRouter() : Router
	{
		return $this->_Router;
	}

	/**
	 * @return bool
	 * @throws Exception
	 */
	protected function onStart(): bool
	{
		$ViewPath = $this->getSetting( 'views', 'path' );
		$BasePath = $this->getBasePath();

		if( $ViewPath )
			Registry::getInstance()->set( "Views.Path", $BasePath.'/'.$ViewPath );

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
		$Output = $this->_Router->run( $this->getParameters() );

		if( !$this->_CaptureOutput )
		{
			echo $Output;
		}
		else
		{
			$this->_Output = $Output;
		}
	}

	/**
	 * This method is called by the route lambdas and handles
	 * instantiating the required controller and calling the correct method.
	 *
	 * @param array $Parameters
	 * @param string $RequestName
	 * @return mixed
	 * @throws MissingMethod
	 * @throws NotFound
	 */
	public function executeController( array $Parameters, string $RequestName = '' ): mixed
	{
		$Parts = explode( '@', $Parameters[ "Controller" ] );

		$Controller = $Parts[ 0 ];
		$Method     = $Parts[ 1 ];

		$Controller = Factory::create( $this, $Controller );

		if( !method_exists( $Controller, $Method ) )
		{
			throw new MissingMethod( "Method '$Method'' not found." );
		}

		$Request = null;

		if( !empty( $RequestName ) )
		{
			$Request = $this->getRequest( $RequestName );

			try
			{
				$Request->processPayload( $Request->getJsonPayload() );
			}
			catch( Exception $e )
			{
				Log::error( $e->getMessage() );
			}
		}

		return $Controller->$Method(
			$Parameters,
			$Request
		);
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	protected function loadRoutes(): void
	{
		$this->_Router = new Router();

		// Configure rate limiting if enabled
		$this->configureRateLimit();

		$this->configure404Route();

		$File = $this->getBasePath().'/config';

		if( $this->getRoutesPath() )
		{
			$File = $this->getRoutesPath();
		}

		if( !file_exists( $File . '/routes.yaml' ) )
		{
			Log::debug( "routes.yaml not found." );
			return;
		}

		try
		{
			$Data = Yaml::parseFile( $File . '/routes.yaml' );
		}
		catch( ParseException $exception )
		{
			Log::error( "Failed to load routes: ".$exception->getMessage() );
			throw new Validation( $exception->getMessage(), [] );
		}

		foreach( $Data[ 'routes' ] as $RouteName => $Route )
		{
			$Request = $Route[ 'request' ] ?? '';
			$Filter = $Route[ 'filter' ] ?? '';

			$RouteMap = $this->addRoute(
				$Route[ 'method' ],
				$Route[ 'route' ],
				$Route[ 'controller' ],
				$Request,
				$Filter
			);
			$RouteMap->setName( $RouteName );
		}
	}

	/**
	 * @param string $Name
	 * @return ?Request
	 * @throws Exception
	 */
	public function getRequest( string $Name ): ?Request
	{
		if( empty( $Name ) )
		{
			return null;
		}

		if( !isset( $this->_Requests[ $Name ] ) )
		{
			throw new Exception( "Request not found: $Name" );
		}

		return $this->_Requests[ $Name ];
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	protected function configure404Route(): void
	{
		$this->_Router->get( "/404",
			function( $Parameters )
			{
				Event::emit( new Http404( $Parameters[ "route" ] ) );

				return self::executeController(
					array_merge(
						$Parameters,
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
		$Source = $this->getSettingManager()?->getSource();

		if( !$Source )
		{
			return;
		}

		// Check if rate limiting extension is available
		if( !class_exists( '\Neuron\Routing\RateLimit\RateLimitConfig' ) )
		{
			return;
		}

		// Configure standard rate_limit
		if( $Source->get( 'rate_limit', 'enabled' ) )
		{
			try
			{
				$Config = \Neuron\Routing\RateLimit\RateLimitConfig::fromSettings( $Source, 'rate_limit' );
				$Filter = new \Neuron\Routing\Filters\RateLimitFilter( $Config );
				$this->_Router->registerFilter( 'rate_limit', $Filter );

				// Apply globally if configured
				if( $Source->get( 'rate_limit', 'global' ) )
				{
					$this->_Router->addFilter( 'rate_limit' );
				}

				Log::debug( 'Rate limiting configured: rate_limit' );
			}
			catch( \Exception $e )
			{
				Log::warning( 'Failed to configure rate limiting: ' . $e->getMessage() );
			}
		}

		// Configure api_limit
		if( $Source->get( 'api_limit', 'enabled' ) )
		{
			try
			{
				$Config = \Neuron\Routing\RateLimit\RateLimitConfig::fromSettings( $Source, 'api_limit' );
				$Filter = new \Neuron\Routing\Filters\RateLimitFilter( $Config );
				$this->_Router->registerFilter( 'api_limit', $Filter );

				Log::debug( 'Rate limiting configured: api_limit' );
			}
			catch( \Exception $e )
			{
				Log::warning( 'Failed to configure API rate limiting: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Clear expired cache entries
	 *
	 * @return int Number of entries removed
	 */
	public function clearExpiredCache(): int
	{
		$Cache = Registry::getInstance()->get( 'ViewCache' );
		
		if( $Cache instanceof \Neuron\Mvc\Cache\ViewCache )
		{
			return $Cache->gc();
		}
		
		// Try to initialize cache from settings if not already loaded
		$Settings = $this->getSettingManager();
		
		if( $Settings )
		{
			try
			{
				$Config = \Neuron\Mvc\Cache\CacheConfig::fromSettings( $Settings->getSource() );

				if( $Config->isEnabled() )
				{
					$BasePath = $this->getBasePath();

					$Storage = \Neuron\Mvc\Cache\Storage\CacheStorageFactory::createFromConfig( $Config, $BasePath );

					return $Storage->gc();
				}
			}
			catch( \Exception $e )
			{
				// Unable to initialize cache
			}
		}
		
		return 0;
	}

	public function beautifyException( \Throwable $e ): string
	{
		// this function should return a nicely formatted HTML representation of the exception
		$ExceptionType = get_class( $e );
		$Message = htmlspecialchars( $e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$File = htmlspecialchars( $e->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$Line = $e->getLine();
		$Trace = nl2br( htmlspecialchars( $e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
		$Html = "<html><head><title>Exception: $ExceptionType</title>
		<style>
		body { font-family: Arial, sans-serif; margin: 20px; }
		h1 { color: #c00; }
		pre { background-color: #f4f4f4; padding: 10px; border: 1px solid #ddd; }
		</style>
		</head><body>";
		$Html .= "<h1>Exception: $ExceptionType</h1>";
		$Html .= "<p><strong>Message:</strong> $Message</p>";
		$Html .= "<p><strong>File:</strong> $File</p>";
		$Html .= "<p><strong>Line:</strong> $Line</p>";
		$Html .= "<h2>Stack Trace:</h2><pre>$Trace</pre>";
		$Html .= "</body></html>";

		return $Html;
	}

	public function handleException( \Throwable $e ) : string
	{
		if( $this->getCaptureOutput() )
		{
			$this->_Output .= $this->beautifyException( $e );
			return $this->_Output;
		}
		else
		{
			return $this->beautifyException( $e );
		}
	}
}

