<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Routing\Router;

interface IController
{
	public function __construct( Router $Router );

	public function renderHtml(  HttpResponseStatus $ResponseCode, array $Data = [], string $Page = "index", string $Layout = "default" ) : string;
	public function renderJson( HttpResponseStatus $ResponseCode, array $Data = [] ) : string;
	public function renderXml( HttpResponseStatus $ResponseCode, array $Data = [] ) : string;
}
