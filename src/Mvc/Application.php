<?php
namespace Neuron\Mvc;

use Neuron\Core\Application\Base;
use Neuron\Core\Facades\Event;
use Neuron\Data\ArrayHelper;
use Neuron\Mvc\Controllers\BadRequestMethodException;
use Neuron\Mvc\Controllers\Factory;
use Neuron\Mvc\Controllers\MissingMethodException;
use Neuron\Mvc\Controllers\NotFoundException;
use Neuron\Mvc\Events\Http404;
use Neuron\Patterns\Registry;
use Neuron\Routing\RequestMethod;
use Neuron\Routing\Router;

class Application extends Base
{
	private Router $_Router;
	private Event  $_Event;

	/**
	 * Application constructor.
	 * @param string $Version
	 * @throws \Exception
	 */
	public function __construct( string $Version )
	{
		parent::__construct( $Version );

		$this->_Router = new Router();
		$this->_Event  = new Event();

		$Route = $this->_Router->get(
			"/404",
			function( $Parameters )
			{
				$this->getEvent()->emit( new Http404() );
				return self::executeController(
					[
						"Controller" => "HttpCodes@_404",
						"NameSpace"  => "Neuron\Mvc\Controllers"
					]
				);
			}
		);
	}

	/**
	 * @return Event
	 */
	public function getEvent(): Event
	{
		return $this->_Event;
	}

	/**
	 * @param string $Method
	 * @param string $Route
	 * @param string $ControllerMethod
	 *
	 * @throws BadRequestMethodException
	 */
	public function addRoute( string $Method, string $Route, string $ControllerMethod )
	{
		switch( RequestMethod::getType( $Method ) )
		{
			case RequestMethod::PUT:
				$Route = $this->_Router->put(
					$Route,
					function( $Parameters )
					{
						return self::executeController( $Parameters );
					}
				);

				break;

			case RequestMethod::GET:
				$Route = $this->_Router->get(
					$Route,
					function( $Parameters )
					{
						return self::executeController( $Parameters );
					}
				);
				break;

			case RequestMethod::POST:
				$Route = $this->_Router->post(
					$Route,
					function( $Parameters )
					{
						return self::executeController( $Parameters );
					}
				);
				break;

			case RequestMethod::DELETE:
				$Route = $this->_Router->delete(
					$Route,
					function( $Parameters )
					{
						return self::executeController( $Parameters );
					}
				);
				break;

			case RequestMethod::UNKNOWN:
				throw new BadRequestMethodException();
		}

		$Route->Payload = [ "Controller" => $ControllerMethod ];
	}

	public function getRouter() : Router
	{
		return $this->_Router;
	}

	/**
	 * @return mixed|void
	 * @throws \Exception
	 * @throws MissingMethodException
	 * @throws BadRequestMethodException
	 * @throws NotFoundException
	 */
	protected function onRun()
	{
		$this->_Router->run( $this->getParameters() );
	}

	/**
	 * This static method is called by the route lambdas and handles
	 * instantiating the required controller and calling the correct method.
	 *
	 * @param $Parameters
	 * @return mixed
	 * @throws MissingMethodException
	 * @throws NotFoundException
	 */
	public static function executeController( $Parameters )
	{
		$Parts = explode( '@', $Parameters[ "Controller" ] );

		$Controller = $Parts[ 0 ];
		$Method     = $Parts[ 1 ];

		if( ArrayHelper::hasKey( $Parameters, "NameSpace" ) )
		{
			$NameSpace = $Parameters[ "NameSpace" ];
		}
		else
		{
			$NameSpace = Registry::getInstance()
										->get( "Controllers.NameSpace" );
		}

		$Controller = Factory::create( $Controller, $NameSpace );

		if( !method_exists( $Controller, $Method ) )
		{
			throw new MissingMethodException( "Method '$Method'' not found." );
		}

		return $Controller->$Method();
	}
}

