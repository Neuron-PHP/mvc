<?php
namespace Neuron\Mvc;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Data\Filters\Get;
use Neuron\Data\Filters\Server;
use Neuron\Data\Objects\Version;
use Neuron\Data\Settings\Source\Yaml;
use Neuron\Patterns\Registry;

/**
 * Initialize the application.
 *
 * @param string $configPath
 * @return Application
 * @throws \Exception
 */

function boot( string $configPath ) : Application
{
	/** @var Neuron\Data\Settings\Source\ISettingSource $settings */

	try
	{
		$settings = new Yaml( "$configPath/neuron.yaml" );
		$basePath = $settings->get( 'system', 'base_path' );
	}
	catch( \Exception $e )
	{
		$settings = null;
		$basePath = getenv( 'SYSTEM_BASE_PATH' ) ? : '.';
	}

	$version = \Neuron\Data\Factories\Version::fromFile( "$basePath/.version.json" );

	try
	{
		$app = new Application( $version->getAsString(), $settings );
	}
	catch( \Throwable $e )
	{
		echo Application::beautifyException( $e );
		exit( 1 );
	}

	return $app;
}

/**
 * Dispatches the current route mapped in the 'route' GET variable.
 *
 * @param Application $app
 */

function dispatch( Application $app ) : void
{
	$route = Get::filterScalar( 'route' ) ?? "";

	try
	{
		$type = Server::filterScalar( 'REQUEST_METHOD' ) ?? "GET";

		// Support HTML form method spoofing via _method field
		// HTML forms can only submit GET/POST, so frameworks use a hidden _method field
		// to indicate PUT/DELETE/PATCH requests
		if( $type === 'POST' && isset( $_POST['_method'] ) )
		{
			$spoofedMethod = strtoupper( $_POST['_method'] );
			if( in_array( $spoofedMethod, [ 'PUT', 'DELETE', 'PATCH' ] ) )
			{
				$type = $spoofedMethod;
			}
		}

		$app->run(
			[
				"type"  => $type,
				"route" => $route
			]
		);
	}
	catch( \Throwable $e )
	{
		echo $app->handleException( $e );
	}
}

/**
 * Clear expired cache entries
 *
 * @param Application $app
 * @return int Number of entries removed
 */
function clearExpiredCache( Application $app ) : int
{
	return $app->clearExpiredCache();
}

/**
 * Render a partial view from the shared directory.
 * This function looks for a file named _{name}.php in the shared views directory.
 * @param string $name The name of the partial (without underscore prefix or .php extension)
 * @param array $data Optional data array to pass to the partial as variables
 * @return void
 * @throws NotFound
 */
function partial( string $name, array $data = [] ) : void
{
	$path = Registry::getInstance()
						 ->get( "Views.Path" );

	if( !$path )
	{
		$basePath = Registry::getInstance()->get( "Base.Path" );
		$path = "$basePath/resources/views";
	}

	$view = "$path/shared/_$name.php";

	if( !file_exists( $view ) )
	{
		throw new NotFound( "Partial not found: $view" );
	}

	// Extract data array as variables in the partial's scope
	extract( $data );

	ob_start();
	require( $view );
	$content = ob_get_contents();
	ob_end_clean();

	echo $content;
}
