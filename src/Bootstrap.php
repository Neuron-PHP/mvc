<?php
use Neuron\Data\Filter\Get;
use Neuron\Data\Filter\Server;
use Neuron\Data\Object\Version;
use Neuron\Data\Setting\Source\Ini;
use Neuron\Mvc\Application;
use Neuron\Patterns\Registry;

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
	$Settings = new Ini( "$ConfigPath/config.ini" );

	Registry::getInstance()
			  ->set( 'Settings', $Settings );

	$BasePath = $Settings->get( 'system', 'base_path' );

	$Version = new Version();
	$Version->loadFromFile( "$BasePath/version.json" );

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

	if( $Route && $Route[ 0 ] != "/" )
	{
		$Route = "/".$Route;
	}

	try
	{
		$App->run(
			[
				"type"  => Server::filterScalar( 'REQUEST_METHOD' ),
				"route" => $Route
			]
		);
	}
	catch( Exception $e )
	{
		echo 'Ouch.';
	}
}
