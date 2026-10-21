<?php

namespace Tests\Mvc\Components;

use Neuron\Data\Settings\Source\Yaml;
use Neuron\Mvc\Application;
use Neuron\Mvc\Components\ComponentLoader;
use Neuron\Mvc\Views\ViewLocator;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;
use Tests\Mvc\Components\Fixtures\RecordingProvider;

/**
 * Boots a real Application against a temp project whose vendor/ declares a
 * web component, and checks the component's routes and views arrive.
 */
class ApplicationComponentsTest extends TestCase
{
	private string $base;
	private string $pkg;

	protected function setUp(): void
	{
		parent::setUp();
		Registry::getInstance()->reset();
		RecordingProvider::reset();

		$this->base = sys_get_temp_dir() . '/neuron_components_' . uniqid();
		$this->pkg = $this->base . '/vendor/acme/board';

		mkdir( $this->base . '/config', 0777, true );
		mkdir( $this->base . '/app/Controllers', 0777, true );
		mkdir( $this->base . '/vendor/composer', 0777, true );
		mkdir( $this->pkg . '/src/Controllers', 0777, true );
		mkdir( $this->pkg . '/resources/views', 0777, true );

		file_put_contents( $this->base . '/vendor/composer/installed.json', json_encode( [
			'packages' => [
				[
					'name'  => 'acme/board',
					'extra' => [ 'neuron' => [ 'mvc-provider' => RecordingProvider::class ] ],
				],
			],
		] ) );

		file_put_contents( $this->base . '/config/routing.yaml', <<<'YAML'
controller_paths:
  - path: app/Controllers
    namespace: TestComponentsApp\Controllers
YAML
		);

		file_put_contents( $this->base . '/app/Controllers/HomeController.php', <<<'PHP'
<?php
namespace TestComponentsApp\Controllers;
use Neuron\Routing\Attributes\Get;
class HomeController
{
	#[Get('/', name: 'home')]
	public function index() {}
}
PHP
		);

		file_put_contents( $this->pkg . '/src/Controllers/BoardController.php', <<<'PHP'
<?php
namespace TestComponentsPkg\Controllers;
use Neuron\Routing\Attributes\Get;
class BoardController
{
	#[Get('/admin/board', name: 'board.index')]
	public function index() {}
}
PHP
		);

		// The scanner reflects on loaded classes; each test writes the same
		// classes to a fresh temp dir, so only load them once per process.
		if( !class_exists( 'TestComponentsApp\\Controllers\\HomeController', false ) )
		{
			require_once $this->base . '/app/Controllers/HomeController.php';
		}

		if( !class_exists( 'TestComponentsPkg\\Controllers\\BoardController', false ) )
		{
			require_once $this->pkg . '/src/Controllers/BoardController.php';
		}

		RecordingProvider::$controllerPaths = [ [ 'path' => $this->pkg . '/src/Controllers', 'namespace' => 'TestComponentsPkg\\Controllers' ] ];
		RecordingProvider::$viewPaths = [ $this->pkg . '/resources/views' ];
	}

	protected function tearDown(): void
	{
		$this->rmrf( $this->base );
		Registry::getInstance()->reset();
		RecordingProvider::reset();
		parent::tearDown();
	}

	private function rmrf( string $dir ): void
	{
		if( !is_dir( $dir ) )
		{
			return;
		}

		foreach( array_diff( scandir( $dir ), [ '.', '..' ] ) as $entry )
		{
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rmrf( $path ) : unlink( $path );
		}

		rmdir( $dir );
	}

	private function writeNeuronYaml( string $extra = '' ): void
	{
		file_put_contents( $this->base . '/config/neuron.yaml', <<<YAML
system:
  base_path: {$this->base}
views:
  path: resources/views
{$extra}
YAML
		);
	}

	private function boot(): Application
	{
		return new Application( '1.0.0', new Yaml( $this->base . '/config/neuron.yaml' ) );
	}

	public function testComponentRoutesViewsAndRegisterAreApplied(): void
	{
		$this->writeNeuronYaml();

		$app = $this->boot();

		$this->assertNotNull( $app->getRouter()->getRouteByName( 'home' ), 'site route still registered' );
		$this->assertNotNull( $app->getRouter()->getRouteByName( 'board.index' ), 'component route registered' );
		$this->assertSame( [ $app ], RecordingProvider::$registeredWith );
		$this->assertSame( [ RecordingProvider::class ], ComponentLoader::loaded() );

		$this->assertContains( $this->pkg . '/resources/views', ( new ViewLocator() )->paths() );

		// At start-up the application re-orders view roots so the site's own
		// come first and anything appended during boot (components) follows.
		$registerViewPaths = new \ReflectionMethod( $app, 'registerViewPaths' );
		$registerViewPaths->invoke( $app );

		$paths = ( new ViewLocator() )->paths();
		$this->assertSame( $this->base . '/resources/views', $paths[0], 'site views first' );
		$this->assertContains( $this->pkg . '/resources/views', $paths );
	}

	public function testDisabledComponentIsSkipped(): void
	{
		$this->writeNeuronYaml( "components:\n  disabled:\n    - " . RecordingProvider::class );

		$app = $this->boot();

		$this->assertNotNull( $app->getRouter()->getRouteByName( 'home' ) );
		$this->assertNull( $app->getRouter()->getRouteByName( 'board.index' ) );
		$this->assertSame( [], RecordingProvider::$registeredWith );
		$this->assertSame( [], ComponentLoader::loaded() );
	}

	public function testDiscoveryCanBeTurnedOff(): void
	{
		$this->writeNeuronYaml( "components:\n  discovery: false" );

		$app = $this->boot();

		$this->assertNull( $app->getRouter()->getRouteByName( 'board.index' ) );
		$this->assertSame( [], RecordingProvider::$registeredWith );
	}

	public function testAddControllerPathReturnsRouteCountAndToleratesMissingDirectory(): void
	{
		$this->writeNeuronYaml( "components:\n  discovery: false" );

		$app = $this->boot();

		$this->assertSame( 1, $app->addControllerPath( $this->pkg . '/src/Controllers', 'TestComponentsPkg\\Controllers' ) );
		$this->assertNotNull( $app->getRouter()->getRouteByName( 'board.index' ) );
		$this->assertSame( 0, $app->addControllerPath( $this->base . '/nowhere', 'Nope' ) );
	}
}
