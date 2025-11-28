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
	/**
	 * Constructor for the controller.
	 *
	 * @param Application|null $app The application instance.
	 */
	public function __construct( ?Application $app );

	/**
	 * Renders an HTML response.
	 *
	 * @param HttpResponseStatus $responseCode The HTTP response status code.
	 * @param array $data Data to be passed to the view.
	 * @param string $page The view page to render.
	 * @param string $layout The layout to use for rendering.
	 * @param bool|null $cacheEnabled Whether caching is enabled for this response.
	 * @return string The rendered HTML content.
	 */
	public function renderHtml(  HttpResponseStatus $responseCode, array $data = [], string $page = "index", string $layout = "default", ?bool $cacheEnabled = null ) : string;

	/**
	 * Renders a Markdown response.
	 *
	 * @param HttpResponseStatus $responseCode The HTTP response status code.
	 * @param array $data Data to be passed to the view.
	 * @param string $page The view page to render.
	 * @param string $layout The layout to use for rendering.
	 * @param bool|null $cacheEnabled Whether caching is enabled for this response.
	 * @return string The rendered Markdown content.
	 */
	public function renderMarkdown( HttpResponseStatus $responseCode, array $data = [], string $page = "index", string $layout = "default", ?bool $cacheEnabled = null ) : string;

	/**
	 * Renders a JSON response.
	 *
	 * @param HttpResponseStatus $responseCode The HTTP response status code.
	 * @param array $data Data to be included in the JSON response.
	 * @return string The rendered JSON content.
	 */
	public function renderJson( HttpResponseStatus $responseCode, array $data = [] ) : string;

	/**
	 * Renders an XML response.
	 *
	 * @param HttpResponseStatus $responseCode The HTTP response status code.
	 * @param array $data Data to be included in the XML response.
	 * @return string The rendered XML content.
	 */
	public function renderXml( HttpResponseStatus $responseCode, array $data = [] ) : string;
}
