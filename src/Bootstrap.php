<?php
namespace Neuron\Mvc;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Data\Filter\Get;
use Neuron\Data\Filter\Server;
use Neuron\Data\Object\Version;
use Neuron\Data\Setting\Source\Yaml;
use Neuron\Patterns\Registry;

/**
 * Initialize the application.
 *
 * @param string $ConfigPath
 * @return Application
 * @throws \Exception
 */

function boot( string $ConfigPath ) : Application
{
	/** @var Neuron\Data\Setting\Source\ISettingSource $Settings */

	try
	{
		$Settings = new Yaml( "$ConfigPath/config.yaml" );
		$BasePath = $Settings->get( 'system', 'base_path' );
	}
	catch( \Exception $e )
	{
		$Settings = null;
		$BasePath = getenv( 'SYSTEM_BASE_PATH' ) ? : '.';
	}

	$Version = new Version();
	$Version->loadFromFile( "$BasePath/.version.json" );

	try
	{
		$App = new Application( $Version->getAsString(), $Settings );
	}
	catch( \Throwable $e )
	{
		echo Application::beautifyException( $e );
		exit( 1 );
	}

	return $App;
}

/**
 * Dispatches the current route mapped in the 'route' GET variable.
 *
 * @param Application $App
 */

function dispatch( Application $App ) : void
{
	$Route = Get::filterScalar( 'route' ) ?? "";

	try
	{
		$Type = Server::filterScalar( 'REQUEST_METHOD' ) ?? "GET";

		// Support HTML form method spoofing via _method field
		// HTML forms can only submit GET/POST, so frameworks use a hidden _method field
		// to indicate PUT/DELETE/PATCH requests
		if( $Type === 'POST' && isset( $_POST['_method'] ) )
		{
			$SpoofedMethod = strtoupper( $_POST['_method'] );
			if( in_array( $SpoofedMethod, [ 'PUT', 'DELETE', 'PATCH' ] ) )
			{
				$Type = $SpoofedMethod;
			}
		}

		$App->run(
			[
				"type"  => $Type,
				"route" => $Route
			]
		);
	}
	catch( \Throwable $e )
	{
		echo $App->handleException( $e );
	}
}

/**
 * Clear expired cache entries
 *
 * @param Application $App
 * @return int Number of entries removed
 */
function clearExpiredCache( Application $App ) : int
{
	return $App->clearExpiredCache();
}

/**
 * Render a partial view from the shared directory.
 * This function looks for a file named _{name}.php in the shared views directory.
 * @param string $name The name of the partial (without underscore prefix or .php extension)
 * @param array $Data Optional data array to pass to the partial as variables
 * @return void
 * @throws NotFound
 */
function partial( string $name, array $Data = [] ) : void
{
	$Path = Registry::getInstance()
						 ->get( "Views.Path" );

	if( !$Path )
	{
		$BasePath = Registry::getInstance()->get( "Base.Path" );
		$Path = "$BasePath/resources/views";
	}

	$View = "$Path/shared/_$name.php";

	if( !file_exists( $View ) )
	{
		throw new NotFound( "Partial not found: $View" );
	}

	// Extract data array as variables in the partial's scope
	extract( $Data );

	ob_start();
	require( $View );
	$Content = ob_get_contents();
	ob_end_clean();

	echo $Content;
}
