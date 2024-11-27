<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Mvc\Application;
use Neuron\Mvc\Views\Html;
use Neuron\Mvc\Views\Json;
use Neuron\Mvc\Views\View;
use Neuron\Mvc\Views\Xml;
use Neuron\Routing\RequestMethod;
use Neuron\Routing\Router;

class Base implements IController
{
	private Router  $_Router;

	public function __construct( Router $Router )
	{
		$this->setRouter( $Router );
	}

	public function renderHtml( array $Data = [], string $Page = "index", string $Layout = "default" ) : string
	{
		$View = ( new Html() )
			->setController( (new \ReflectionClass( static::class ))->getShortName() )
			->setLayout( $Layout )
			->setPage( $Page );

		return $View->render( $Data );
	}

	public function renderJson( array $Data = [] ): string
	{
		$View = new Json();

		return $View->render( $Data );
	}

	public function renderXml( array $Data = [] ): string
	{
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
	 */
	public static function register( Application $App, string $Route = '' )
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

	protected static function registerIndex( Application $App, string $Controller, string $Route )
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

	protected static function registerAdd( Application $App, string $Controller, string $Route )
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

	protected static function registerShow( Application $App, string $Controller, string $Route )
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

	protected static function registerCreate( Application $App, string $Controller, string $Route )
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

	protected static function registerEdit( Application $App, string $Controller, string $Route )
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

	protected static function registerUpdate( Application $App, string $Controller, string $Route )
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

	protected static function registerDelete( Application $App, string $Controller, string $Route )
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
