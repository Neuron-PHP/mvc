<?php

namespace Mvc\Controllers;

use Neuron\Mvc\Application;
use Neuron\Mvc\Controllers\Base;
use Neuron\Routing\RequestMethod;
use PHPUnit\Framework\TestCase;

class ControllerTest extends Base
{
	public function index()
	{}

	public function add()
	{}

	public function show()
	{}

	public function create()
	{}

	public function edit()
	{}

	public function update()
	{}

	public function delete()
	{}
}


class BaseTest extends TestCase
{

	public function testRegister()
	{
		$App = new Application( "1" );

		ControllerTest::register( $App, "controller" );

		// index
		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller" ) != null
		);

		// new

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller/new" ) != null
		);

		// show

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller/1" ) != null
		);

		// create

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::POST, "/controller/create" ) != null
		);

		// edit

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller/edit/1" ) != null
		);

		// update

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::POST, "/controller/1" ) != null
		);

		// delete

		$this->assertTrue(
			$App->getRouter()->getRoute( RequestMethod::GET, "/controller/delete/1" ) != null
		);

	}
}
