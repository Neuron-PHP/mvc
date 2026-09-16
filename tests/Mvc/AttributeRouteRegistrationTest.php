<?php

namespace Tests\Mvc;

use Neuron\Data\Settings\Source\Yaml;
use Neuron\Mvc\Application;
use Neuron\Patterns\Registry;
use Neuron\Routing\Exceptions\DuplicateRouteException;
use PHPUnit\Framework\TestCase;

class AttributeRouteRegistrationTest extends TestCase
{
	private string $base;

	protected function setUp(): void
	{
		parent::setUp();
		Registry::getInstance()->reset();

		$this->base = sys_get_temp_dir() . '/neuron_attr_routes_' . uniqid();
		mkdir( $this->base . '/config', 0777, true );
		mkdir( $this->base . '/app/Controllers', 0777, true );
		mkdir( $this->base . '/cms/Controllers', 0777, true );

		file_put_contents( $this->base . '/config/neuron.yaml', <<<YAML
system:
  base_path: {$this->base}
views:
  path: views
YAML
		);

		file_put_contents( $this->base . '/config/routing.yaml', <<<'YAML'
controller_paths:
  - path: app/Controllers
    namespace: TestAttrApp\Controllers
  - path: cms/Controllers
    namespace: TestAttrCms\Controllers
YAML
		);

		file_put_contents( $this->base . '/app/Controllers/HomeController.php', <<<'PHP'
<?php
namespace TestAttrApp\Controllers;
use Neuron\Routing\Attributes\Get;
class HomeController
{
	#[Get('/home', name: 'home')]
	public function index() {}
}
PHP
		);

		file_put_contents( $this->base . '/cms/Controllers/Home.php', <<<'PHP'
<?php
namespace TestAttrCms\Controllers;
use Neuron\Routing\Attributes\Get;
class Home
{
	#[Get('/', name: 'home')]
	public function index() {}
}
PHP
		);

		file_put_contents( $this->base . '/cms/Controllers/Login.php', <<<'PHP'
<?php
namespace TestAttrCms\Controllers;
use Neuron\Routing\Attributes\Get;
class Login
{
	#[Get('/login', name: 'login')]
	public function show() {}
}
PHP
		);

		require_once $this->base . '/app/Controllers/HomeController.php';
		require_once $this->base . '/cms/Controllers/Home.php';
		require_once $this->base . '/cms/Controllers/Login.php';
	}

	protected function tearDown(): void
	{
		@unlink( $this->base . '/app/Controllers/HomeController.php' );
		@unlink( $this->base . '/cms/Controllers/Home.php' );
		@unlink( $this->base . '/cms/Controllers/Login.php' );
		@unlink( $this->base . '/config/routing.yaml' );
		@unlink( $this->base . '/config/neuron.yaml' );
		@rmdir( $this->base . '/app/Controllers' );
		@rmdir( $this->base . '/app' );
		@rmdir( $this->base . '/cms/Controllers' );
		@rmdir( $this->base . '/cms' );
		@rmdir( $this->base . '/config' );
		@rmdir( $this->base );
		parent::tearDown();
	}

	public function testDuplicateHomeNameFailsStartup(): void
	{
		$this->expectException( DuplicateRouteException::class );
		$this->expectExceptionMessage( "Duplicate route name detected: 'home'" );

		new Application( '1.0.0', new Yaml( $this->base . '/config/neuron.yaml' ) );
	}
}
