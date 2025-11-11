<?php

namespace Neuron\Mvc\Controllers;

use Neuron\Data\Setting\Source\ISettingSource;
use Neuron\Mvc\Application;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Routing\Router;

/**
 * Controller interface defining the contract for MVC controllers.
 * 
 * All controllers in the Neuron MVC framework must implement this interface
 * to ensure consistent rendering capabilities across different response formats.
 * Controllers handle request processing and response generation for HTML, JSON, and XML.
 * 
 * @package Neuron\Mvc\Controllers
 */
interface IController
{
	public function __construct( ?Application $app );

	public function renderHtml(  HttpResponseStatus $responseCode, array $data = [], string $page = "index", string $layout = "default", ?bool $cacheEnabled = null ) : string;
	public function renderJson( HttpResponseStatus $responseCode, array $data = [] ) : string;
	public function renderXml( HttpResponseStatus $responseCode, array $data = [] ) : string;
}
