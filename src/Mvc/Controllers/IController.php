<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Routing\Router;

interface IController
{
	public function __construct( Router $Router );

	public function renderHtml(  int $ResponseCode, array $Data = [], string $Page = "index", string $Layout = "default" ) : string;
	public function renderJson( int $ResponseCode, array $Data = [] ) : string;
	public function renderXml( int $ResponseCode, array $Data = [] ) : string;
}
