<?php

namespace Neuron\Mvc\Cli\Commands\Generate;

use Neuron\Cli\Commands\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * CLI command for generating listener classes.
 * Automatically updates config/event-listeners.yaml
 */
class ListenerCommand extends Command
{
	private string $_ProjectPath;
	private string $_StubPath;

	public function __construct()
	{
		$this->_ProjectPath = getcwd();
		$this->_StubPath = __DIR__ . '/stubs';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'listener:generate';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Generate a listener class and register it';
	}

	/**
	 * Configure the command
	 */
	public function configure(): void
	{
		$this->addArgument( 'name', true, 'Listener name (e.g., SendWelcomeEmail)' );
		$this->addOption( 'event', null, true, 'Event class to listen to (e.g., App\\Events\\UserRegistered)' );
		$this->addOption( 'namespace', null, true, 'Listener namespace', 'App\\Listeners' );
		$this->addOption( 'force', 'f', false, 'Overwrite existing files' );
	}

	/**
	 * Execute the command
	 */
	public function execute(): int
	{
		$this->output->info( "\n╔═══════════════════════════════════════╗" );
		$this->output->info( "║  Listener Generator                  ║" );
		$this->output->info( "╚═══════════════════════════════════════╝\n" );

		// Get listener name
		$listenerName = $this->input->getArgument( 'name' );
		if( empty( $listenerName ) )
		{
			$this->output->error( 'Listener name is required' );
			return 1;
		}

		// Get event class
		$eventClass = $this->input->getOption( 'event' );
		if( empty( $eventClass ) )
		{
			$this->output->error( 'Event class is required (--event option)' );
			$this->output->info( 'Example: --event="App\\Events\\UserRegistered"' );
			return 1;
		}

		// Get namespace
		$namespace = $this->input->getOption( 'namespace', 'App\\Listeners' );
		$namespace = rtrim( $namespace, '\\' );

		// Generate listener
		if( !$this->generateListener( $listenerName, $namespace, $eventClass ) )
		{
			return 1;
		}

		// Update event-listeners.yaml
		if( !$this->updateEventListeners( $eventClass, $namespace . '\\' . $listenerName ) )
		{
			$this->output->warning( 'Listener created but failed to update event-listeners.yaml' );
			$this->output->info( 'You may need to register it manually in config/event-listeners.yaml' );
			return 1;
		}

		$this->output->newLine();
		$this->output->success( 'Listener generated and registered successfully!' );
		$this->output->info( "\nThe listener has been registered in config/event-listeners.yaml" );
		$this->output->info( "Event: {$eventClass}" );
		$this->output->info( "Listener: {$namespace}\\{$listenerName}" );

		return 0;
	}

	/**
	 * Generate listener class
	 */
	private function generateListener( string $listenerName, string $namespace, string $eventClass ): bool
	{
		// Determine file path
		$namespacePath = str_replace( '\\', '/', str_replace( 'App\\', '', $namespace ) );
		$listenerDir = $this->_ProjectPath . '/app/' . $namespacePath;
		$listenerFile = $listenerDir . '/' . $listenerName . '.php';

		// Check if exists
		if( file_exists( $listenerFile ) && !$this->input->hasOption( 'force' ) )
		{
			$this->output->error( "Listener already exists: {$listenerFile}" );
			$this->output->info( 'Use --force to overwrite' );
			return false;
		}

		// Create directory
		if( !is_dir( $listenerDir ) )
		{
			if( !mkdir( $listenerDir, 0755, true ) )
			{
				$this->output->error( "Could not create directory: {$listenerDir}" );
				return false;
			}
		}

		// Load stub
		$stub = $this->loadStub( 'listener.stub' );
		if( !$stub )
		{
			$this->output->error( 'Could not load listener stub' );
			return false;
		}

		// Extract event name from class
		$eventName = $this->getClassName( $eventClass );

		// Replace placeholders
		$content = str_replace(
			[ '{{namespace}}', '{{class}}', '{{eventClass}}', '{{eventName}}' ],
			[ $namespace, $listenerName, $eventClass, $eventName ],
			$stub
		);

		// Write file
		if( file_put_contents( $listenerFile, $content ) === false )
		{
			$this->output->error( 'Could not write listener file' );
			return false;
		}

		$this->output->success( "Created listener: {$listenerFile}" );
		return true;
	}

	/**
	 * Update event-listeners.yaml configuration
	 */
	private function updateEventListeners( string $eventClass, string $listenerClass ): bool
	{
		$configPath = $this->_ProjectPath . '/config';
		$configFile = $configPath . '/event-listeners.yaml';

		// Ensure config directory exists
		if( !is_dir( $configPath ) )
		{
			if( !mkdir( $configPath, 0755, true ) )
			{
				$this->output->error( "Could not create config directory: {$configPath}" );
				return false;
			}
		}

		// Load existing config or create new
		$config = [ 'events' => [] ];

		if( file_exists( $configFile ) )
		{
			try
			{
				$existingConfig = Yaml::parseFile( $configFile );
				if( isset( $existingConfig['events'] ) )
				{
					$config = $existingConfig;
				}
			}
			catch( \Exception $e )
			{
				$this->output->error( 'Could not parse event-listeners.yaml: ' . $e->getMessage() );
				return false;
			}
		}

		// Generate event key (camelCase version of class name)
		$eventKey = $this->generateEventKey( $eventClass );

		// Check if event exists
		if( !isset( $config['events'][$eventKey] ) )
		{
			// Create new event entry
			$config['events'][$eventKey] = [
				'class' => $eventClass,
				'listeners' => [ $listenerClass ]
			];

			$this->output->info( "Created new event entry: {$eventKey}" );
		}
		else
		{
			// Add listener to existing event
			if( !in_array( $listenerClass, $config['events'][$eventKey]['listeners'] ) )
			{
				$config['events'][$eventKey]['listeners'][] = $listenerClass;
				$this->output->info( "Added listener to existing event: {$eventKey}" );
			}
			else
			{
				$this->output->warning( "Listener already registered for this event" );
			}
		}

		// Write updated config
		try
		{
			$yaml = Yaml::dump( $config, 4, 2 );
			if( file_put_contents( $configFile, $yaml ) === false )
			{
				$this->output->error( 'Could not write event-listeners.yaml' );
				return false;
			}

			$this->output->success( "Updated: {$configFile}" );
			return true;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Could not generate YAML: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Generate event key from class name (e.g., App\Events\UserRegistered -> userRegistered)
	 */
	private function generateEventKey( string $eventClass ): string
	{
		$className = $this->getClassName( $eventClass );
		return lcfirst( $className );
	}

	/**
	 * Get class name from fully qualified class name
	 */
	private function getClassName( string $fqcn ): string
	{
		$parts = explode( '\\', $fqcn );
		return end( $parts );
	}

	/**
	 * Load a stub file
	 */
	private function loadStub( string $name ): ?string
	{
		$stubFile = $this->_StubPath . '/' . $name;

		if( !file_exists( $stubFile ) )
		{
			return null;
		}

		return file_get_contents( $stubFile );
	}
}
