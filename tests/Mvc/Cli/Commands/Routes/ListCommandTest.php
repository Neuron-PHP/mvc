<?php

namespace Neuron\Tests\Mvc\Cli\Commands\Routes;

use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Mvc\Cli\Commands\Routes\ListCommand;
use PHPUnit\Framework\TestCase;

/**
 * Test the routes:list command
 */
class ListCommandTest extends TestCase
{
	private string $testConfigPath;

	protected function setUp(): void
	{
		parent::setUp();

		// Create a temporary config directory for testing
		$this->testConfigPath = sys_get_temp_dir() . '/neuron_test_config_' . uniqid();
		mkdir( $this->testConfigPath, 0755, true );

		// Create test controllers directory
		$controllersPath = $this->testConfigPath . '/app/Controllers';
		mkdir( $controllersPath, 0755, true );

		// Create a test neuron.yaml configuration
		$configContent = <<<YAML
system:
  base_path: {$this->testConfigPath}

controllers:
  paths:
    - path: app/Controllers
      namespace: TestApp\\Controllers
YAML;

		file_put_contents( $this->testConfigPath . '/neuron.yaml', $configContent );

		// Create a test controller with attributes
		$controllerContent = <<<'PHP'
<?php

namespace TestApp\Controllers;

use Neuron\Routing\Attributes\Get;
use Neuron\Routing\Attributes\Post;
use Neuron\Routing\Attributes\RouteGroup;

#[RouteGroup(prefix: '/test')]
class TestController
{
	#[Get('/', name: 'test.index')]
	public function index(): string
	{
		return 'test';
	}

	#[Get('/show/:id', name: 'test.show')]
	public function show(): string
	{
		return 'show';
	}

	#[Post('/store', name: 'test.store')]
	public function store(): string
	{
		return 'store';
	}
}
PHP;

		file_put_contents( $controllersPath . '/TestController.php', $controllerContent );

		// Load the test controller class only if not already loaded
		if( !class_exists( 'TestApp\\Controllers\\TestController' ) )
		{
			require_once $controllersPath . '/TestController.php';
		}
	}

	protected function tearDown(): void
	{
		// Clean up test files
		if( is_dir( $this->testConfigPath ) )
		{
			$this->deleteDirectory( $this->testConfigPath );
		}

		parent::tearDown();
	}

	private function deleteDirectory( string $dir ): void
	{
		if( !is_dir( $dir ) )
		{
			return;
		}

		$files = array_diff( scandir( $dir ), ['.', '..'] );

		foreach( $files as $file )
		{
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->deleteDirectory( $path ) : unlink( $path );
		}

		rmdir( $dir );
	}

	public function testListCommandScansAttributeRoutes(): void
	{
		$command = new ListCommand();
		$input = new Input( ['--config=' . $this->testConfigPath] );
		$output = new Output();

		$command->setInput( $input );
		$command->setOutput( $output );

		// Capture output
		ob_start();
		$exitCode = $command->execute();
		$outputContent = ob_get_clean();

		$this->assertEquals( 0, $exitCode, 'Command should execute successfully' );

		// Verify the output contains our test routes
		$this->assertStringContainsString( 'test.index', $outputContent );
		$this->assertStringContainsString( 'test.show', $outputContent );
		$this->assertStringContainsString( 'test.store', $outputContent );
		$this->assertStringContainsString( '/test/', $outputContent );
		$this->assertStringContainsString( '/test/show/:id', $outputContent );
		$this->assertStringContainsString( '/test/store', $outputContent );
		$this->assertStringContainsString( 'GET', $outputContent );
		$this->assertStringContainsString( 'POST', $outputContent );
	}

	public function testListCommandWithMissingConfig(): void
	{
		$command = new ListCommand();
		$input = new Input( ['--config=/nonexistent/path'] );
		$output = new Output();

		$command->setInput( $input );
		$command->setOutput( $output );

		ob_start();
		$exitCode = $command->execute();
		$outputContent = ob_get_clean();

		$this->assertEquals( 1, $exitCode, 'Command should fail with missing config' );
		$this->assertStringContainsString( 'Configuration directory not found', $outputContent );
	}

	public function testListCommandFiltersByMethod(): void
	{
		$command = new ListCommand();
		$input = new Input( [
			'--config=' . $this->testConfigPath,
			'--method=POST'
		] );
		$output = new Output();

		$command->setInput( $input );
		$command->setOutput( $output );

		ob_start();
		$exitCode = $command->execute();
		$outputContent = ob_get_clean();

		$this->assertEquals( 0, $exitCode );

		// Should contain POST route
		$this->assertStringContainsString( 'test.store', $outputContent );
		// Should NOT contain GET routes
		$this->assertStringNotContainsString( 'test.index', $outputContent );
		$this->assertStringNotContainsString( 'test.show', $outputContent );
	}
}
