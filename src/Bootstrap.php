<?php
use Neuron\Data\Filter\Get;
use Neuron\Data\Filter\Server;
use Neuron\Data\Object\Version;
use Neuron\Data\Setting\Source\Yaml;
use Neuron\Mvc\Application;

/**
 * Initialize the application.
 *
 * @param string $ConfigPath
 * @return Application
 * @throws Exception
 */

function Boot( string $ConfigPath ) : Application
{
	/** @var Neuron\Data\Setting\Source\ISettingSource $Settings */

	try
	{
		$Settings = new Yaml( "$ConfigPath/config.yaml" );
		$BasePath = $Settings->get( 'system', 'base_path' );
	}
	catch( Exception $e )
	{
		$Settings = null;
		$BasePath = getenv( 'SYSTEM_BASE_PATH' ) ? : '.';
	}

	$Version = new Version();
	$Version->loadFromFile( "$BasePath/.version.json" );

	return new Application( $Version->getAsString(), $Settings );
}

/**
 * Dispatches the current route mapped in the 'route' GET variable.
 *
 * @param Application $App
 */

function Dispatch( Application $App ) : void
{
	$Route = Get::filterScalar( 'route' ) ?? "";

	try
	{
		$Type = Server::filterScalar( 'REQUEST_METHOD' ) ?? "GET";

		$App->run(
			[
				"type"  => $Type,
				"route" => $Route
			]
		);
	}
	catch( Exception $e )
	{
		echo 'Ouch.';
	}
}

/**
 * Clear expired cache entries
 *
 * @param Application $App
 * @return int Number of entries removed
 */
function ClearExpiredCache( Application $App ) : int
{
	return $App->clearExpiredCache();
}
