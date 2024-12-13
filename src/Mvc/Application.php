<?php
namespace Neuron\Mvc;

use Exception;
use Neuron\Core\Application\Base;
use Neuron\Core\CrossCutting\Event;
use Neuron\Data\ArrayHelper;
use Neuron\Data\Setting\Source\ISettingSource;
use Neuron\Mvc\Controllers\BadRequestMethodException;
use Neuron\Mvc\Controllers\Factory;
use Neuron\Mvc\Controllers\MissingMethodException;
use Neuron\Mvc\Controllers\NotFoundException;
use Neuron\Mvc\Events\Http404;
use Neuron\Patterns\Registry;
use Neuron\Routing\RequestMethod;
use Neuron\Routing\Router;

/**
 * Class Application
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends Base
{
	private Router $_Router;

	/**
	 * Application constructor.
	 * @param string $Version
	 * @param ISettingSource|null $Source
	 * @throws Exception
	 */
	public function __construct( string $Version, ?ISettingSource $Source = null )
	{
		parent::__construct( $Version, $Source );

		$this->_Router = new Router();

		$this->_Router->get(
			"/404",
			function( $Parameters )
			{
				Event::emit( new Http404( $Parameters[ "route" ] ) );

				return self::executeController(
					array_merge(
						$Parameters,
						[
							"Controller" => "HttpCodes@code404",
							"NameSpace"  => "Neuron\Mvc\Controllers"
						]
					)
				);
			}
		);
	}

	/**
	 * @param string $Method
	 * @param string $Route
	 * @param string $ControllerMethod
	 * @return Application
	 *
	 * @throws BadRequestMethodException
	 */
	public function addRoute( string $Method, string $Route, string $ControllerMethod ) : Application
	{
		switch( RequestMethod::getType( $Method ) )
		{
			case RequestMethod::PUT:
				$Route = $this->_Router->put(
					$Route,
					function( $Parameters )
					{
						return $this->executeController( $Parameters );
					}
				);

				break;

			case RequestMethod::GET:
				$Route = $this->_Router->get(
					$Route,
					function( $Parameters )
					{
						return $this->executeController( $Parameters );
					}
				);
				break;

			case RequestMethod::POST:
				$Route = $this->_Router->post(
					$Route,
					function( $Parameters )
					{
						return $this->executeController( $Parameters );
					}
				);
				break;

			case RequestMethod::DELETE:
				$Route = $this->_Router->delete(
					$Route,
					function( $Parameters )
					{
						return $this->executeController( $Parameters );
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
	 */
	public function executeController( $Parameters ): mixed
	{
		$Parts = explode( '@', $Parameters[ "Controller" ] );

		$Controller = $Parts[ 0 ];
		$Method     = $Parts[ 1 ];
		$NameSpace  = "App\Controllers";

		if( ArrayHelper::hasKey( $Parameters, "NameSpace" ) )
		{
			$NameSpace = $Parameters[ "NameSpace" ];
		}

		$Controller = Factory::create( $this, $Controller, $NameSpace );

		if( !method_exists( $Controller, $Method ) )
		{
			throw new MissingMethodException( "Method '$Method'' not found." );
		}

		return $Controller->$Method( $Parameters );
	}
}

