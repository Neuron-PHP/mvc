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
	public function __construct( string $Version, ?ISettingSource $Source = null )
	{
		parent::__construct( $Version, $Source );

		$this->_RoutesPath = '';

		Registry::getInstance()->set( 'BasePath', $this->getBasePath() );

		$RoutesPath = $this->getSetting( 'routes_path', 'system' );
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
	 * @return Application
	 *
	 * @throws BadRequestMethod
	 * @throws Exception
	 */
	public function addRoute( string $Method, string $Route, string $ControllerMethod, string $Request = '' ) : Application
	{
		switch( RequestMethod::getType( $Method ) )
		{
			case RequestMethod::PUT:
				$Route = $this->_Router->put(
					$Route,
					function( $Parameters ) use ( $Request )
					{
						return $this->executeController( $Parameters, $Request );
					}
				);

				break;

			case RequestMethod::GET:
				$Route = $this->_Router->get(
					$Route,
					function( $Parameters ) use ( $Request )
					{
						return $this->executeController( $Parameters, $Request );
					}
				);
				break;

			case RequestMethod::POST:
				$Route = $this->_Router->post(
					$Route,
					function( $Parameters ) use ( $Request )
					{
						return $this->executeController( $Parameters, $Request );
					}
				);
				break;

			case RequestMethod::DELETE:
				$Route = $this->_Router->delete(
					$Route,
					function( $Parameters ) use ( $Request )
					{
						return $this->executeController( $Parameters, $Request );
					}
				);
				break;

			case RequestMethod::UNKNOWN:
				throw new BadRequestMethod();
		}

		$Route->Payload = [ "Controller" => $ControllerMethod ];

		return $this;
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
		$ViewPath = $this->getSetting( 'path', 'views' );
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

		foreach( $Data[ 'routes' ] as $Route )
		{
			$Request = $Route[ 'request' ] ?? '';

			$this->addRoute(
				$Route[ 'method' ],
				$Route[ 'route' ],
				$Route[ 'controller' ],
				$Request
			);
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
					$CachePath = $BasePath . DIRECTORY_SEPARATOR . $Config->getCachePath();
					
					$Storage = new \Neuron\Mvc\Cache\Storage\FileCacheStorage( $CachePath );
					
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
}

