<?php
namespace Neuron\Mvc;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Core\Registry\RegistryKeys;
use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;
use Neuron\Data\Filters\Get;
use Neuron\Data\Filters\Server;
use Neuron\Data\Objects\Version;
use Neuron\Data\Settings\SettingManager;
use Neuron\Data\Settings\SettingManagerFactory;
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
	/** @var SettingManager $settings */
	$settings = null;
	$basePath = null;

	try
	{
		// Determine environment from APP_ENV (defaults to 'production')
		$environment = getenv( 'APP_ENV' ) ?: 'production';

		// Use SettingManagerFactory for comprehensive configuration loading
		// This automatically loads:
		// 1. neuron.yaml (base configuration)
		// 2. environments/{env}.yaml (environment-specific config if exists)
		// 3. secrets.yml.enc (encrypted secrets if exists)
		// 4. environments/{env}.secrets.yml.enc (environment secrets if exists)
		// 5. Environment variables (highest priority)
		$settings = SettingManagerFactory::create( $environment, $configPath );

		$basePath = $settings->get( 'system', 'base_path' );

		// If base_path not in settings, use environment variable or current directory
		if( empty( $basePath ) )
		{
			$basePath = getenv( 'SYSTEM_BASE_PATH' ) ?: '.';
		}
	}
	catch( \Exception $e )
	{
		// Log the configuration error for debugging
		\Neuron\Log\Log::error(
			sprintf(
				"Configuration loading failed: %s\nTrace: %s",
				$e->getMessage(),
				$e->getTraceAsString()
			)
		);

		// Fall back to environment/default path
		$basePath = getenv( 'SYSTEM_BASE_PATH' ) ?: '.';
		$settings = null;
	}

	// Ensure basePath is set
	if( empty( $basePath ) )
	{
		$basePath = getenv( 'SYSTEM_BASE_PATH' ) ?: '.';
	}

	$version = \Neuron\Data\Factories\Version::fromFile( "$basePath/.version.json" );
	$app = new Application( $version->getAsString(), $settings );

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
		// Check if this exception should pass through to caller (e.g., public/index.php)
		// Applications can configure exception classes via neuron.yaml under 'exceptions.passthrough'
		$passthroughExceptions = Registry::getInstance()->get( RegistryKeys::PASSTHROUGH_EXCEPTIONS_LEGACY ) ?? [];
		$exceptionClass = get_class( $e );

		\Neuron\Log\Log::debug( 'Exception caught: ' . $exceptionClass );
		\Neuron\Log\Log::debug( 'Passthrough list: ' . json_encode( $passthroughExceptions ) );
		\Neuron\Log\Log::debug( 'Is in array: ' . ( in_array( $exceptionClass, $passthroughExceptions ) ? 'YES' : 'NO' ) );

		if( in_array( $exceptionClass, $passthroughExceptions ) )
		{
			\Neuron\Log\Log::debug( 'Re-throwing exception' );
			throw $e;
		}

		// For all other exceptions, handle them normally
		\Neuron\Log\Log::debug( 'Handling exception normally' );
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
 * @param IFileSystem|null $fs File system implementation (null = use real file system)
 * @return void
 * @throws NotFound
 */
function partial( string $name, array $data = [], ?IFileSystem $fs = null ) : void
{
	$fs = $fs ?? new RealFileSystem();

	$path = Registry::getInstance()
						 ->get( RegistryKeys::VIEWS_PATH );

	if( !$path )
	{
		$basePath = Registry::getInstance()->get( RegistryKeys::BASE_PATH );
		$path = "$basePath/resources/views";
	}

	$view = "$path/shared/_$name.php";

	if( !$fs->fileExists( $view ) )
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
