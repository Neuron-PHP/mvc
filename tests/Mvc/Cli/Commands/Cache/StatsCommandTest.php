<?php

namespace Tests\Mvc\Cli\Commands\Cache;

use Neuron\Mvc\Cli\Commands\Cache\StatsCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use PHPUnit\Framework\TestCase;

class StatsCommandTest extends TestCase
{
	private StatsCommand $command;
	private Output $output;
	
	protected function setUp(): void
	{
		$this->command = new StatsCommand();
		$this->output = $this->createMock( Output::class );
		$this->command->setOutput( $this->output );
	}
	
	public function testGetName(): void
	{
		$this->assertEquals( 'cache:stats', $this->command->getName() );
	}
	
	public function testGetDescription(): void
	{
		$this->assertEquals( 
			'Display MVC view cache statistics', 
			$this->command->getDescription() 
		);
	}
	
	public function testConfigure(): void
	{
		$this->command->configure();
		
		$options = $this->command->getOptions();
		
		// Check that all expected options are configured
		$this->assertArrayHasKey( 'config', $options );
		$this->assertArrayHasKey( 'json', $options );
		$this->assertArrayHasKey( 'detailed', $options );
		
		// Check option configurations
		$this->assertTrue( $options['config']['hasValue'] );
		$this->assertFalse( $options['json']['hasValue'] );
		$this->assertFalse( $options['detailed']['hasValue'] );
	}
	
	public function testExecuteWithMissingConfig(): void
	{
		// Create input with no valid config path
		$input = new Input( [] );
		$this->command->setInput( $input );
		
		// Mock output to expect error message
		$this->output->expects( $this->once() )
			->method( 'error' )
			->with( $this->stringContains( 'Configuration directory not found' ) );
		
		$this->output->expects( $this->once() )
			->method( 'info' )
			->with( 'Use --config to specify the configuration directory' );
		
		// Execute should return error code
		$result = $this->command->execute();
		$this->assertEquals( 1, $result );
	}
	
	public function testExecuteWithValidConfig(): void
	{
		// Create a temporary config directory
		$tempDir = sys_get_temp_dir() . '/neuron_mvc_test_' . uniqid();
		mkdir( $tempDir );
		
		// Create a minimal neuron.yaml
		$config = "cache:\n  enabled: false\n  path: cache/views\n  ttl: 3600\n";
		file_put_contents( $tempDir . '/neuron.yaml', $config );
		
		try
		{
			// Create input with config option
			$input = new Input( ['--config=' . $tempDir] );
			$this->command->setInput( $input );
			
			// Since cache is disabled, we expect certain outputs
			$this->output->expects( $this->any() )
				->method( 'title' );
			
			$this->output->expects( $this->any() )
				->method( 'info' );
			
			$this->output->expects( $this->any() )
				->method( 'write' );
			
			// Execute should succeed even with disabled cache
			$result = $this->command->execute();
			$this->assertEquals( 0, $result );
			
			// Clean up
			unlink( $tempDir . '/neuron.yaml' );
			rmdir( $tempDir );
		}
		catch( \Exception $e )
		{
			// Clean up on failure
			if( file_exists( $tempDir . '/neuron.yaml' ) )
			{
				unlink( $tempDir . '/neuron.yaml' );
			}
			if( is_dir( $tempDir ) )
			{
				rmdir( $tempDir );
			}
			
			throw $e;
		}
	}
	
	public function testJsonOutput(): void
	{
		// Create a temporary config directory
		$tempDir = sys_get_temp_dir() . '/neuron_mvc_test_' . uniqid();
		mkdir( $tempDir );
		
		// Create a minimal neuron.yaml
		$config = "cache:\n  enabled: true\n  path: $tempDir/cache\n  ttl: 3600\n";
		file_put_contents( $tempDir . '/neuron.yaml', $config );
		
		// Create cache directory
		mkdir( $tempDir . '/cache' );
		
		try
		{
			// Create input with json option
			$input = new Input( ['--config=' . $tempDir, '--json'] );
			$this->command->setInput( $input );
			
			// Expect JSON output
			$this->output->expects( $this->once() )
				->method( 'write' )
				->with( $this->callback( function( $output ) {
					// Check if output is valid JSON
					$data = json_decode( $output, true );
					return $data !== null && isset( $data['enabled'] );
				}));
			
			// Execute
			$result = $this->command->execute();
			$this->assertEquals( 0, $result );
			
			// Clean up
			rmdir( $tempDir . '/cache' );
			unlink( $tempDir . '/neuron.yaml' );
			rmdir( $tempDir );
		}
		catch( \Exception $e )
		{
			// Clean up on failure
			if( is_dir( $tempDir . '/cache' ) )
			{
				rmdir( $tempDir . '/cache' );
			}
			if( file_exists( $tempDir . '/neuron.yaml' ) )
			{
				unlink( $tempDir . '/neuron.yaml' );
			}
			if( is_dir( $tempDir ) )
			{
				rmdir( $tempDir );
			}
			
			throw $e;
		}
	}
}
