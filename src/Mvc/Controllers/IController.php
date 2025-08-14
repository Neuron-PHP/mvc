<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Data\Setting\Source\ISettingSource;
use Neuron\Mvc\Application;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Routing\Router;

interface IController
{
	public function __construct( ?Application $app );

	public function renderHtml(  HttpResponseStatus $ResponseCode, array $Data = [], string $Page = "index", string $Layout = "default", ?bool $CacheEnabled = null ) : string;
	public function renderJson( HttpResponseStatus $ResponseCode, array $Data = [] ) : string;
	public function renderXml( HttpResponseStatus $ResponseCode, array $Data = [] ) : string;
}
