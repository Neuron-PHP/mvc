<?php

namespace Neuron\Mvc\Cli\Commands\Routes;

use Neuron\Cli\Commands\Command;
use Neuron\Data\Setting\Source\Yaml;
use Symfony\Component\Yaml\Yaml as YamlParser;

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
		if( $this->input->hasOption( 'json' ) )
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
	 * Load routes from configuration
	 * 
	 * @param string $configPath
	 * @return array
	 */
	private function loadRoutes( string $configPath ): array
	{
		$routes = [];
		
		// Check for routes file location from config
		$configFile = $configPath . '/neuron.yaml';
		$routesPath = $configPath;

		if( file_exists( $configFile ) )
		{
			try
			{
				$settings = new Yaml( $configFile );
				$customRoutesPath = $settings->get( 'system', 'routes_path' );
				if( $customRoutesPath )
				{
					// Make path absolute if relative
					if( !str_starts_with( $customRoutesPath, '/' ) )
					{
						$basePath = $settings->get( 'system', 'base_path' ) ?? dirname( $configPath );
						$customRoutesPath = $basePath . '/' . $customRoutesPath;
					}
					if( is_dir( $customRoutesPath ) )
					{
						$routesPath = $customRoutesPath;
					}
				}
			}
			catch( \Exception $e )
			{
				$this->output->warning( 'Could not load neuron.yaml: ' . $e->getMessage() );
			}
		}
		
		// Load routes.yaml
		$routesFile = $routesPath . '/routes.yaml';
		
		if( !file_exists( $routesFile ) )
		{
			$this->output->error( 'Routes file not found: ' . $routesFile );
			return [];
		}
		
		try
		{
			$data = YamlParser::parseFile( $routesFile );
			
			if( !is_array( $data ) )
			{
				$this->output->error( 'Invalid routes file format' );
				return [];
			}
			
			// Check if routes are nested under 'routes' key
			if( isset( $data['routes'] ) && is_array( $data['routes'] ) )
			{
				$data = $data['routes'];
			}
			
			foreach( $data as $routeName => $routeConfig )
			{
				// Parse route configuration
				$route = $this->parseRoute( $routeName, $routeConfig );
				if( $route )
				{
					$routes[] = $route;
				}
			}
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error loading routes: ' . $e->getMessage() );
			return [];
		}
		
		return $routes;
	}
	
	/**
	 * Parse a single route configuration
	 * 
	 * @param string $routeName
	 * @param mixed $config
	 * @return array|null
	 */
	private function parseRoute( string $routeName, $config ): ?array
	{
		if( !is_array( $config ) )
		{
			return null;
		}
		
		// Extract the actual route pattern
		$pattern = $config['route'] ?? $routeName;
		
		// Extract controller and action from controller string (e.g., "App\Controllers\StaticPages@index")
		$controllerString = $config['controller'] ?? 'Unknown';
		if( strpos( $controllerString, '@' ) !== false )
		{
			list( $controller, $action ) = explode( '@', $controllerString, 2 );
		}
		else
		{
			$controller = $controllerString;
			$action = $config['action'] ?? 'index';
		}
		
		// Extract HTTP method
		$httpMethod = strtoupper( $config['method'] ?? $config['type'] ?? 'GET' );
		
		// Extract parameters from route pattern (e.g., :page, :title)
		$parameters = [];
		if( preg_match_all( '/:(\w+)/', $pattern, $matches ) )
		{
			$parameters = $matches[1];
		}
		
		// Also check for explicit request parameters
		if( isset( $config['request'] ) && is_array( $config['request'] ) )
		{
			foreach( $config['request'] as $param => $rules )
			{
				if( !in_array( $param, $parameters ) )
				{
					$parameters[] = $param;
				}
			}
		}
		
		return [
			'name' => $routeName,
			'pattern' => $pattern,
			'controller' => $controller,
			'action' => $action,
			'method' => $httpMethod,
			'parameters' => $parameters,
		];
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
		$this->output->title( 'MVC Routes' );
		
		// Prepare table headers
		$headers = ['Name', 'Pattern', 'Method', 'Controller', 'Action'];
		
		// Prepare table rows
		$rows = [];
		$namedRoutes = 0;
		
		foreach( $routes as $route )
		{
			$routeName = $route['name'] ?? '';
			if( !empty( $routeName ) )
			{
				$namedRoutes++;
			}
			
			$rows[] = [
				$routeName ?: '-',
				$route['pattern'],
				$route['method'],
				$route['controller'],
				$route['action']
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
