<?php

namespace Neuron\Mvc\Cli\Commands\Routes;

use Neuron\Cli\Commands\Command;
use Neuron\Data\Settings\Source\Yaml;
use Neuron\Mvc\Components\ComponentLoader;
use Neuron\Routing\RouteScanner;

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
		$this->addOption( 'source', 's', true, 'Filter by source: "app" or a component namespace' );
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

		// Try routing.yaml first (new location), fall back to neuron.yaml (legacy)
		$routingFile = $configPath . '/routing.yaml';
		$neuronFile = $configPath . '/neuron.yaml';

		$configFile = null;
		$configKey = null;

		if( file_exists( $routingFile ) )
		{
			$configFile = $routingFile;
			$configKey = 'controller_paths';
		}
		elseif( file_exists( $neuronFile ) )
		{
			$configFile = $neuronFile;
			$configKey = 'controllers.paths';
		}
		else
		{
			$this->output->error( 'Configuration file not found' );
			$this->output->info( 'Expected: ' . $routingFile . ' or ' . $neuronFile );
			return [];
		}

		try
		{
			$settings = new Yaml( $configFile );

			// Use actual filesystem path
			// Don't use system.base_path which is for runtime/container deployment
			// If config is in a 'config' directory, use parent; otherwise use config path itself
			$basePath = (basename($configPath) === 'config') ? dirname($configPath) : $configPath;

			// Get controller paths from configuration
			if( $configKey === 'controller_paths' )
			{
				// New format in routing.yaml
				$controllerPathsConfig = $settings->getSection( 'controller_paths' );
			}
			else
			{
				// Legacy format in neuron.yaml
				$controllerPathsConfig = $settings->get( 'controllers', 'paths' );
			}

			if( empty( $controllerPathsConfig ) )
			{
				$this->output->warning( 'No controller paths configured' );
				$this->output->info( 'Add controller paths to ' . basename( $configFile ) . ':' );
				if( $configKey === 'controller_paths' )
				{
					$this->output->info( '  controller_paths:' );
					$this->output->info( '    - path: app/Controllers' );
					$this->output->info( '      namespace: App\\Controllers' );
				}
				else
				{
					$this->output->info( '  controllers:' );
					$this->output->info( '    paths:' );
					$this->output->info( '      - path: app/Controllers' );
					$this->output->info( '        namespace: App\\Controllers' );
				}
				return [];
			}

			foreach( $controllerPathsConfig as $pathConfig )
			{
				array_push( $routes, ...$this->scanPath( $basePath . '/' . $pathConfig['path'], $pathConfig['namespace'], 'app', true ) );
			}

			array_push( $routes, ...$this->componentRoutes( $basePath, file_exists( $neuronFile ) ? new Yaml( $neuronFile ) : null ) );
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error loading configuration: ' . $e->getMessage() );
			return [];
		}

		return $routes;
	}

	/**
	 * Routes declared by installed web components, in the order the
	 * application would load them. Honours `components.discovery` and
	 * `components.disabled` from neuron.yaml when present.
	 *
	 * @param string $basePath
	 * @param Yaml|null $settings
	 * @return array
	 */
	private function componentRoutes( string $basePath, ?Yaml $settings ): array
	{
		$discovery = $settings?->get( 'components', 'discovery' );

		if( in_array( $discovery, [ false, 0, '0', 'false', 'off', 'no' ], true ) )
		{
			return [];
		}

		$disabled = $settings?->get( 'components', 'disabled' );
		$disabled = is_array( $disabled ) ? array_map( fn( $c ) => ltrim( (string)$c, '\\' ), $disabled ) : [];

		$routes = [];

		foreach( ( new ComponentLoader() )->discover( $basePath ) as $provider )
		{
			if( in_array( $provider, $disabled, true ) )
			{
				continue;
			}

			// Show the component by its namespace (Neuron\LaborBoard), not "Provider"
			$separator = strrpos( $provider, '\\' );
			$source = $separator === false ? $provider : substr( $provider, 0, $separator );

			try
			{
				foreach( $provider::controllerPaths() as $entry )
				{
					array_push( $routes, ...$this->scanPath( (string)( $entry['path'] ?? '' ), (string)( $entry['namespace'] ?? '' ), $source, false ) );
				}
			}
			catch( \Throwable $e )
			{
				$this->output->warning( "Error loading component $provider: " . $e->getMessage() );
			}
		}

		return $routes;
	}

	/**
	 * Scan one controller directory into route rows.
	 *
	 * @param string $directory
	 * @param string $namespace
	 * @param string $source 'app' or the component that owns the directory
	 * @param bool $warnMissing Report a missing directory (site paths are
	 *        configured by hand; a component's are expected to exist quietly)
	 * @return array
	 */
	private function scanPath( string $directory, string $namespace, string $source, bool $warnMissing ): array
	{
		if( !is_dir( $directory ) )
		{
			if( $warnMissing )
			{
				$this->output->warning( "Controller directory not found: $directory" );
			}

			return [];
		}

		$routes = [];

		try
		{
			foreach( ( new RouteScanner() )->scanDirectory( $directory, $namespace ) as $def )
			{
				$routes[] = [
					'name'       => $def->name ?? '-',
					'pattern'    => $def->path,
					'method'     => $def->method,
					'controller' => $def->controller,
					'action'     => $def->action,
					'filters'    => $def->filters,
					'source'     => $source,
				];
			}
		}
		catch( \Exception $e )
		{
			$this->output->warning( "Error scanning $directory: " . $e->getMessage() );
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

		// Filter by source
		if( $source = $this->input->getOption( 'source' ) )
		{
			$routes = array_filter( $routes, function( $route ) use ( $source ) {
				return stripos( $route['source'] ?? 'app', $source ) !== false;
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

		// Prepare table headers; the Source column only earns its space once
		// a component has contributed routes.
		$sources = array_unique( array_map( fn( $r ) => $r['source'] ?? 'app', $routes ) );
		$showSource = count( $sources ) > 1 || !in_array( 'app', $sources, true );

		$headers = ['Name', 'Method', 'Pattern', 'Controller', 'Action', 'Filters'];

		if( $showSource )
		{
			$headers[] = 'Source';
		}

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

			$row = [
				$routeName ?: '-',
				$route['method'],
				$route['pattern'],
				$shortController,
				$route['action'],
				$filtersStr
			];

			if( $showSource )
			{
				$row[] = $route['source'] ?? 'app';
			}

			$rows[] = $row;
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
