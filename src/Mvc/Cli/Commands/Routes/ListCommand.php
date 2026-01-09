<?php

namespace Neuron\Mvc\Cli\Commands\Routes;

use Neuron\Cli\Commands\Command;
use Neuron\Data\Settings\Source\Yaml;
use Neuron\Routing\RouteScanner;
use Neuron\Log\Log;

/**
 * CLI command for listing MVC routes.
 */
class ListCommand extends Command
{
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'routes:list';
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'List all registered MVC routes';
	}
	
	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'config', 'c', true, 'Path to configuration directory' );
		$this->addOption( 'controller', null, true, 'Filter by controller name' );
		$this->addOption( 'method', 'm', true, 'Filter by HTTP method (GET, POST, etc.)' );
		$this->addOption( 'pattern', 'p', true, 'Search routes by pattern' );
		$this->addOption( 'json', 'j', false, 'Output routes in JSON format' );
	}
	
	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		// Get configuration path
		$configPath = $this->input->getOption( 'config', $this->findConfigPath() );
		
		if( !$configPath || !is_dir( $configPath ) )
		{
			$this->output->error( 'Configuration directory not found: ' . ($configPath ?: 'none specified') );
			$this->output->info( 'Use --config to specify the configuration directory' );
			return 1;
		}
		
		// Load routes
		$routes = $this->loadRoutes( $configPath );
		
		if( empty( $routes ) )
		{
			$this->output->warning( 'No routes found' );
			return 0;
		}
		
		// Apply filters
		$routes = $this->filterRoutes( $routes );
		
		if( empty( $routes ) )
		{
			$this->output->warning( 'No routes match the specified filters' );
			return 0;
		}
		
		// Output routes
		if( $this->input->getOption( 'json' ) )
		{
			$this->outputJson( $routes );
		}
		else
		{
			$this->outputTable( $routes );
		}
		
		return 0;
	}
	
	/**
	 * Load routes from controller attributes
	 *
	 * @param string $configPath
	 * @return array
	 */
	private function loadRoutes( string $configPath ): array
	{
		$routes = [];

		// Load neuron.yaml configuration
		$configFile = $configPath . '/neuron.yaml';

		if( !file_exists( $configFile ) )
		{
			$this->output->error( 'Configuration file not found: ' . $configFile );
			return [];
		}

		try
		{
			$settings = new Yaml( $configFile );

			// Get base path
			$basePath = $settings->get( 'system', 'base_path' ) ?? dirname( $configPath );

			// Get controller paths from configuration
			$controllerPathsConfig = $settings->get( 'controllers', 'paths' );

			if( empty( $controllerPathsConfig ) )
			{
				$this->output->warning( 'No controller paths configured in neuron.yaml' );
				$this->output->info( 'Add controller paths to neuron.yaml under controllers.paths:' );
				$this->output->info( '  controllers:' );
				$this->output->info( '    paths:' );
				$this->output->info( '      - path: app/Controllers' );
				$this->output->info( '        namespace: App\\Controllers' );
				return [];
			}

			// Scan controllers using RouteScanner
			$scanner = new RouteScanner();

			foreach( $controllerPathsConfig as $pathConfig )
			{
				$directory = $basePath . '/' . $pathConfig['path'];
				$namespace = $pathConfig['namespace'];

				if( !is_dir( $directory ) )
				{
					$this->output->warning( "Controller directory not found: $directory" );
					continue;
				}

				try
				{
					$routeDefinitions = $scanner->scanDirectory( $directory, $namespace );

					foreach( $routeDefinitions as $def )
					{
						$routes[] = [
							'name' => $def->name ?? '-',
							'pattern' => $def->path,
							'method' => $def->method,
							'controller' => $def->controller,
							'action' => $def->action,
							'filters' => $def->filters,
						];
					}
				}
				catch( \Exception $e )
				{
					$this->output->warning( "Error scanning $directory: " . $e->getMessage() );
				}
			}
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error loading configuration: ' . $e->getMessage() );
			return [];
		}

		return $routes;
	}
	/**
	 * Filter routes based on command options
	 * 
	 * @param array $routes
	 * @return array
	 */
	private function filterRoutes( array $routes ): array
	{
		// Filter by controller
		if( $controller = $this->input->getOption( 'controller' ) )
		{
			$routes = array_filter( $routes, function( $route ) use ( $controller ) {
				return stripos( $route['controller'], $controller ) !== false;
			});
		}
		
		// Filter by HTTP method
		if( $method = $this->input->getOption( 'method' ) )
		{
			$method = strtoupper( $method );
			$routes = array_filter( $routes, function( $route ) use ( $method ) {
				return $route['method'] === $method;
			});
		}
		
		// Filter by pattern
		if( $pattern = $this->input->getOption( 'pattern' ) )
		{
			$routes = array_filter( $routes, function( $route ) use ( $pattern ) {
				return stripos( $route['pattern'], $pattern ) !== false;
			});
		}
		
		return array_values( $routes );
	}
	
	/**
	 * Output routes in table format
	 *
	 * @param array $routes
	 * @return void
	 */
	private function outputTable( array $routes ): void
	{
		$this->output->title( 'MVC Routes (Attribute-Based)' );

		// Prepare table headers
		$headers = ['Name', 'Method', 'Pattern', 'Controller', 'Action', 'Filters'];

		// Prepare table rows
		$rows = [];
		$namedRoutes = 0;

		foreach( $routes as $route )
		{
			$routeName = $route['name'] ?? '';
			if( !empty( $routeName ) && $routeName !== '-' )
			{
				$namedRoutes++;
			}

			// Format filters for display
			$filters = $route['filters'] ?? [];
			$filtersStr = is_array( $filters ) ? implode( ', ', $filters ) : $filters;
			if( empty( $filtersStr ) )
			{
				$filtersStr = '-';
			}

			// Shorten controller name for display
			$controller = $route['controller'];
			$controllerParts = explode( '\\', $controller );
			$shortController = end( $controllerParts );

			$rows[] = [
				$routeName ?: '-',
				$route['method'],
				$route['pattern'],
				$shortController,
				$route['action'],
				$filtersStr
			];
		}

		// Display the table using Output's table method
		$this->output->table( $headers, $rows );

		$this->output->newLine();
		$this->output->info( 'Total routes: ' . count( $routes ) );
		$this->output->info( 'Named routes: ' . $namedRoutes );

		// Show method distribution
		$methods = array_count_values( array_column( $routes, 'method' ) );
		$methodStr = [];
		foreach( $methods as $method => $count )
		{
			$methodStr[] = "$method: $count";
		}
		$this->output->write( 'Methods: ' . implode( ', ', $methodStr ) );
	}
	
	/**
	 * Output routes in JSON format
	 * 
	 * @param array $routes
	 * @return void
	 */
	private function outputJson( array $routes ): void
	{
		$this->output->write( json_encode( $routes, JSON_PRETTY_PRINT ) );
	}
	
	/**
	 * Try to find the configuration directory
	 * 
	 * @return string|null
	 */
	private function findConfigPath(): ?string
	{
		// Try common locations
		$locations = [
			getcwd() . '/config',
			dirname( getcwd() ) . '/config',
			dirname( getcwd(), 2 ) . '/config',
			dirname( __DIR__, 5 ) . '/config',
			dirname( __DIR__, 6 ) . '/config',
			dirname( __DIR__, 5 ) . '/examples/config',
		];
		
		foreach( $locations as $location )
		{
			if( is_dir( $location ) )
			{
				return $location;
			}
		}
		
		return null;
	}
}
