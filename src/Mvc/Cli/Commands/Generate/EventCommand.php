<?php

namespace Neuron\Mvc\Cli\Commands\Generate;

use Neuron\Cli\Commands\Command;

/**
 * CLI command for generating event classes.
 */
class EventCommand extends Command
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
		return 'event:generate';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Generate an event class';
	}

	/**
	 * Configure the command
	 */
	public function configure(): void
	{
		$this->addArgument( 'name', true, 'Event name (e.g., UserRegistered)' );
		$this->addOption( 'namespace', null, true, 'Event namespace', 'App\\Events' );
		$this->addOption( 'property', null, true, 'Add property (format: name:type)' );
		$this->addOption( 'force', 'f', false, 'Overwrite existing files' );
	}

	/**
	 * Execute the command
	 */
	public function execute(): int
	{
		$this->output->info( "\n╔═══════════════════════════════════════╗" );
		$this->output->info( "║  Event Generator                     ║" );
		$this->output->info( "╚═══════════════════════════════════════╝\n" );

		// Get event name
		$eventName = $this->input->getArgument( 'name' );
		if( empty( $eventName ) )
		{
			$this->output->error( 'Event name is required' );
			return 1;
		}

		// Get namespace
		$namespace = $this->input->getOption( 'namespace', 'App\\Events' );
		$namespace = rtrim( $namespace, '\\' );

		// Parse properties
		$properties = $this->parseProperties();

		// Generate event
		if( !$this->generateEvent( $eventName, $namespace, $properties ) )
		{
			return 1;
		}

		$this->output->newLine();
		$this->output->success( 'Event generated successfully!' );
		$this->output->info( "\nNext steps:" );
		$this->output->info( "1. Create a listener: php neuron listener:generate YourListener --event=\"{$namespace}\\{$eventName}\"" );
		$this->output->info( "2. Emit the event in your code: Event::emit( new {$eventName}(...) );" );

		return 0;
	}

	/**
	 * Parse properties from options
	 */
	private function parseProperties(): array
	{
		$properties = [];
		$propertyOptions = $this->input->getOption( 'property' );

		if( empty( $propertyOptions ) )
		{
			return $properties;
		}

		// Handle single property or array
		if( !is_array( $propertyOptions ) )
		{
			$propertyOptions = [ $propertyOptions ];
		}

		foreach( $propertyOptions as $property )
		{
			$parts = explode( ':', $property );
			if( count( $parts ) !== 2 )
			{
				$this->output->warning( "Invalid property format: {$property}. Expected format: name:type" );
				continue;
			}

			$properties[] = [
				'name' => trim( $parts[0] ),
				'type' => trim( $parts[1] )
			];
		}

		return $properties;
	}

	/**
	 * Generate event class
	 */
	private function generateEvent( string $eventName, string $namespace, array $properties ): bool
	{
		// Determine file path
		$namespacePath = str_replace( '\\', '/', str_replace( 'App\\', '', $namespace ) );
		$eventDir = $this->_ProjectPath . '/app/' . $namespacePath;
		$eventFile = $eventDir . '/' . $eventName . '.php';

		// Check if exists
		if( file_exists( $eventFile ) && !$this->input->hasOption( 'force' ) )
		{
			$this->output->error( "Event already exists: {$eventFile}" );
			$this->output->info( 'Use --force to overwrite' );
			return false;
		}

		// Create directory
		if( !is_dir( $eventDir ) )
		{
			if( !mkdir( $eventDir, 0755, true ) )
			{
				$this->output->error( "Could not create directory: {$eventDir}" );
				return false;
			}
		}

		// Load stub
		$stub = $this->loadStub( 'event.stub' );
		if( !$stub )
		{
			$this->output->error( 'Could not load event stub' );
			return false;
		}

		// Build property declarations
		$propertyDeclarations = '';
		$constructorParams = [];
		$constructorBody = '';

		foreach( $properties as $property )
		{
			$propertyDeclarations .= "\tpublic {$property['type']} \${$property['name']};\n";
			$constructorParams[] = "{$property['type']} \${$property['name']}";
			$constructorBody .= "\t\t\$this->{$property['name']} = \${$property['name']};\n";
		}

		// Replace placeholders
		$content = str_replace(
			[ '{{namespace}}', '{{class}}', '{{properties}}', '{{constructorParams}}', '{{constructorBody}}' ],
			[
				$namespace,
				$eventName,
				rtrim( $propertyDeclarations ),
				implode( ', ', $constructorParams ),
				rtrim( $constructorBody )
			],
			$stub
		);

		// Write file
		if( file_put_contents( $eventFile, $content ) === false )
		{
			$this->output->error( 'Could not write event file' );
			return false;
		}

		$this->output->success( "Created event: {$eventFile}" );
		return true;
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
