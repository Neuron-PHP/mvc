<?php

namespace Tests\Mvc\Components;

use Neuron\Core\Registry\RegistryKeys;
use Neuron\Core\System\MemoryFileSystem;
use Neuron\Mvc\Components\ComponentLoader;
use Neuron\Mvc\IMvcApplication;
use Neuron\Mvc\Views\ViewLocator;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;
use Tests\Mvc\Components\Fixtures\BrokenProvider;
use Tests\Mvc\Components\Fixtures\NotAProvider;
use Tests\Mvc\Components\Fixtures\RecordingProvider;

class ComponentLoaderTest extends TestCase
{
	private MemoryFileSystem $fs;

	protected function setUp(): void
	{
		parent::setUp();
		Registry::getInstance()->reset();
		RecordingProvider::reset();

		$this->fs = new MemoryFileSystem();
		$this->fs->setCwd( '/elsewhere' );
	}

	protected function tearDown(): void
	{
		Registry::getInstance()->reset();
		RecordingProvider::reset();
		parent::tearDown();
	}

	private function installed( array $packages, string $vendor = '/site/vendor' ): void
	{
		$this->fs->addDirectory( $vendor );
		$this->fs->addDirectory( $vendor . '/composer' );
		$this->fs->addFile( $vendor . '/composer/installed.json', json_encode( [ 'packages' => $packages ] ) );
	}

	private function package( string $name, string|array|null $provider ): array
	{
		$package = [ 'name' => $name ];

		if( $provider !== null )
		{
			$package['extra'] = [ 'neuron' => [ 'mvc-provider' => $provider ] ];
		}

		return $package;
	}

	// discover()

	public function testDiscoverReadsProvidersFromInstalledJson(): void
	{
		$this->installed( [
			$this->package( 'acme/plain', null ),
			$this->package( 'acme/board', RecordingProvider::class ),
		] );

		$found = ( new ComponentLoader( $this->fs ) )->discover( '/site' );

		$this->assertSame( [ RecordingProvider::class ], $found );
	}

	public function testDiscoverAcceptsListOfProvidersAndLeadingBackslash(): void
	{
		$this->installed( [
			$this->package( 'acme/multi', [ '\\' . RecordingProvider::class, BrokenProvider::class ] ),
		] );

		$found = ( new ComponentLoader( $this->fs ) )->discover( '/site' );

		$this->assertSame( [ RecordingProvider::class, BrokenProvider::class ], $found );
	}

	public function testDiscoverIncludesRootComposerJsonLast(): void
	{
		$this->installed( [ $this->package( 'acme/board', RecordingProvider::class ) ] );
		$this->fs->addFile( '/site/composer.json', json_encode( [
			'extra' => [ 'neuron' => [ 'mvc-provider' => BrokenProvider::class ] ],
		] ) );

		$found = ( new ComponentLoader( $this->fs ) )->discover( '/site' );

		$this->assertSame( [ RecordingProvider::class, BrokenProvider::class ], $found );
	}

	public function testDiscoverDropsMissingAndNonProviderClassesAndDuplicates(): void
	{
		$this->installed( [
			$this->package( 'acme/ghost', 'Acme\\Does\\Not\\Exist' ),
			$this->package( 'acme/wrong', NotAProvider::class ),
			$this->package( 'acme/board', RecordingProvider::class ),
			$this->package( 'acme/board-again', RecordingProvider::class ),
		] );

		$found = ( new ComponentLoader( $this->fs ) )->discover( '/site' );

		$this->assertSame( [ RecordingProvider::class ], $found );
	}

	public function testDiscoverHandlesComposerOneFormat(): void
	{
		$this->fs->addDirectory( '/site/vendor/composer' );
		$this->fs->addFile( '/site/vendor/composer/installed.json', json_encode( [
			$this->package( 'acme/board', RecordingProvider::class ),
		] ) );

		$found = ( new ComponentLoader( $this->fs ) )->discover( '/site' );

		$this->assertSame( [ RecordingProvider::class ], $found );
	}

	public function testDiscoverFallsBackToWorkingDirectoryVendor(): void
	{
		$this->fs->setCwd( '/cwd' );
		$this->installed( [ $this->package( 'acme/board', RecordingProvider::class ) ], '/cwd/vendor' );

		$found = ( new ComponentLoader( $this->fs ) )->discover( '/site-without-vendor' );

		$this->assertSame( [ RecordingProvider::class ], $found );
	}

	public function testDiscoverReturnsEmptyWhenNothingInstalled(): void
	{
		$this->assertSame( [], ( new ComponentLoader( $this->fs ) )->discover( '/site' ) );
	}

	public function testDiscoverIgnoresUnreadableInstalledJson(): void
	{
		$this->fs->addDirectory( '/site/vendor/composer' );
		$this->fs->addFile( '/site/vendor/composer/installed.json', '{not json' );

		$this->assertSame( [], ( new ComponentLoader( $this->fs ) )->discover( '/site' ) );
	}

	// load()

	public function testLoadAppliesPathsViewsAndRegister(): void
	{
		RecordingProvider::$controllerPaths = [ [ 'path' => '/pkg/src/Controllers', 'namespace' => 'Pkg\\Controllers' ] ];
		RecordingProvider::$viewPaths = [ '/pkg/resources/views' ];

		$app = $this->createMock( IMvcApplication::class );
		$app->expects( $this->once() )
			->method( 'addControllerPath' )
			->with( '/pkg/src/Controllers', 'Pkg\\Controllers' )
			->willReturn( 3 );

		$loaded = ( new ComponentLoader( $this->fs ) )->load( $app, [ RecordingProvider::class ] );

		$this->assertSame( [ RecordingProvider::class ], $loaded );
		$this->assertSame( [ $app ], RecordingProvider::$registeredWith );
		$this->assertContains( '/pkg/resources/views', ( new ViewLocator( $this->fs ) )->paths() );
		$this->assertSame( [ RecordingProvider::class ], Registry::getInstance()->get( RegistryKeys::MVC_COMPONENTS ) );
		$this->assertSame( [ RecordingProvider::class ], ComponentLoader::loaded() );
	}

	public function testLoadSkipsDisabledProviders(): void
	{
		$app = $this->createMock( IMvcApplication::class );
		$app->expects( $this->never() )->method( 'addControllerPath' );

		$loaded = ( new ComponentLoader( $this->fs ) )->load( $app, [ RecordingProvider::class ], [ '\\' . RecordingProvider::class ] );

		$this->assertSame( [], $loaded );
		$this->assertSame( [], RecordingProvider::$registeredWith );
		$this->assertSame( [], ComponentLoader::loaded() );
	}

	public function testLoadSurvivesAProviderThatThrows(): void
	{
		$app = $this->createMock( IMvcApplication::class );

		$loaded = ( new ComponentLoader( $this->fs ) )->load( $app, [ BrokenProvider::class, RecordingProvider::class ] );

		$this->assertSame( [ RecordingProvider::class ], $loaded );
		$this->assertCount( 1, RecordingProvider::$registeredWith );
	}

	public function testLoadedIsEmptyBeforeAnyLoad(): void
	{
		$this->assertSame( [], ComponentLoader::loaded() );
	}

	// navigation()

	public function testNavigationCollectsSortsAndFilters(): void
	{
		RecordingProvider::$navigation = [
			[ 'group' => 'system', 'label' => 'Queue', 'route' => 'queue.index', 'icon' => 'bi-stack', 'order' => 50 ],
			[ 'group' => 'system', 'label' => 'Alpha', 'route' => 'alpha.index' ],
			[ 'group' => 'content', 'label' => 'Boards', 'route' => 'boards.index', 'order' => 10 ],
			[ 'group' => 'system', 'label' => 'No route' ],
			'garbage',
		];

		( new ComponentLoader( $this->fs ) )->load( $this->createMock( IMvcApplication::class ), [ RecordingProvider::class, BrokenProvider::class ] );

		$all = ComponentLoader::navigation();

		$this->assertSame( [ 'Boards', 'Queue', 'Alpha' ], array_column( $all, 'label' ) );
		$this->assertSame( RecordingProvider::class, $all[0]['provider'] );
		$this->assertSame( 100, $all[2]['order'] );
		$this->assertNull( $all[2]['icon'] );
		$this->assertSame( 'bi-stack', $all[1]['icon'] );

		$system = ComponentLoader::navigation( 'system' );

		$this->assertSame( [ 'Queue', 'Alpha' ], array_column( $system, 'label' ) );
	}

	public function testNavigationIsEmptyWithoutNavigationProviders(): void
	{
		( new ComponentLoader( $this->fs ) )->load( $this->createMock( IMvcApplication::class ), [] );

		$this->assertSame( [], ComponentLoader::navigation() );
	}
}
