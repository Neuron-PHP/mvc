<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Routing\Router;

interface IController
{
	public function __construct( Router $Router );

	public function renderHtml( array $Data = [], string $Page = "index", string $Layout = "default" ) : string;
	public function renderJson( array $Data = [] ) : string;
	public function renderXml( array $Data = [] ) : string;
}
