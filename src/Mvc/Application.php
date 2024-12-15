<?php
namespace Neuron\Mvc;

use Exception;
use Neuron\Core\Application\Base;
use Neuron\Core\CrossCutting\Event;
use Neuron\Data\Setting\Source\ISettingSource;
use Neuron\Log\Log;
use Neuron\Mvc\Controllers\BadRequestMethodException;
use Neuron\Mvc\Controllers\Factory;
use Neuron\Mvc\Controllers\MissingMethodException;
use Neuron\Mvc\Controllers\NotFoundException;
use Neuron\Mvc\Events\Http404;
use Neuron\Mvc\Requests\Request;
use Neuron\Patterns\Registry;
use Neuron\Routing\RequestMethod;
use Neuron\Routing\Router;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Application
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends Base
{
	private Router $_Router;
	private array $_Requests = [];

	/**
	 * Application constructor.
	 * @param string $Version
	 * @param ISettingSource|null $Source
	 * @throws Exception
	 */
	public function __construct( string $Version, ?ISettingSource $Source = null )
	{
		parent::__construct( $Version, $Source );

		$this->setBasePath(
			$this->getSetting( 'base_path', 'system' ) ?? '.'
		);

		$this->loadRequests();
		$this->loadRoutes();
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	protected function loadRequests(): void
	{
		$RequestPath = $this->getBasePath().'/resources/requests';

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
	 * @throws BadRequestMethodException
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
				throw new BadRequestMethodException();
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
		if( $ViewPath )
			Registry::getInstance()->set( "Views.Path", $ViewPath );

		return parent::onStart();
	}

	/**
	 * @return void
	 * @throws Exception
	 * @throws MissingMethodException
	 * @throws BadRequestMethodException
	 * @throws NotFoundException
	 */
	protected function onRun() : void
	{
		echo $this->_Router->run( $this->getParameters() );
	}

	/**
	 * This method is called by the route lambdas and handles
	 * instantiating the required controller and calling the correct method.
	 *
	 * @param $Parameters
	 * @return mixed
	 * @throws MissingMethodException
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function executeController( array $Parameters, string $RequestName = '' ): mixed
	{
		$Parts = explode( '@', $Parameters[ "Controller" ] );

		$Controller = $Parts[ 0 ];
		$Method     = $Parts[ 1 ];

		$Controller = Factory::create( $this, $Controller );

		if( !method_exists( $Controller, $Method ) )
		{
			throw new MissingMethodException( "Method '$Method'' not found." );
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

		$Data = Yaml::parseFile($this->getBasePath().'/config/routes.yaml' );

		foreach( $Data[ 'routes' ] as $Route )
		{
			$this->addRoute(
				$Route[ 'method' ],
				$Route[ 'route' ],
				$Route[ 'controller' ],
				$Route[ 'request' ]
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
}

