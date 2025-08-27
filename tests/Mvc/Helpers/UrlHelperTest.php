<?php

namespace Tests\Mvc\Helpers;

use Neuron\Mvc\Helpers\UrlHelper;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use Neuron\Routing\RouteMap;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the UrlHelper class.
 * 
 * Tests Rails-style URL generation from named routes with parameter substitution,
 * magic method support, and integration with the Router singleton.
 */
class UrlHelperTest extends TestCase
{
	private Router $router;
	private UrlHelper $urlHelper;

	protected function setUp(): void
	{
		parent::setUp();

		// Create a fresh router instance for testing
		$this->router = new Router();
		$this->urlHelper = new UrlHelper( $this->router );

		// Set up Registry for URL generation
		$registry = Registry::getInstance();
		$registry->set( 'Base.Url', 'https://example.com' );

		// Skip tests if the Router doesn't have the required URL helper methods
		if( !method_exists( $this->router, 'generateUrl' ) )
		{
			$this->markTestSkipped( 'Router URL helper methods not available. Install neuron-php/routing dev version.' );
		}
	}

	protected function tearDown(): void
	{
		parent::tearDown();
	}

	/**
	 * Create a mock RouteMap for testing.
	 */
	private function createMockRoute( string $name, string $path ): RouteMap
	{
		$route = new RouteMap( $path, function() { return 'test'; } );
		$route->setName( $name );
		return $route;
	}

	public function testRoutePathGeneratesRelativeUrl(): void
	{
		// Arrange
		$route = $this->createMockRoute( 'user_profile', '/users/:id' );
		$this->router->get( '/users/:id', function() { return 'test'; } )
		              ->setName( 'user_profile' );

		// Act
		$url = $this->urlHelper->routePath( 'user_profile', ['id' => 123] );

		// Assert
		$this->assertEquals( '/users/123', $url );
	}

	public function testRouteUrlGeneratesAbsoluteUrl(): void
	{
		// Arrange
		$route = $this->createMockRoute( 'user_profile', '/users/:id' );
		$this->router->get( '/users/:id', function() { return 'test'; } )
		              ->setName( 'user_profile' );

		// Act
		$url = $this->urlHelper->routeUrl( 'user_profile', ['id' => 123] );

		// Assert
		$this->assertEquals( 'https://example.com/users/123', $url );
	}

	public function testRoutePathReturnsNullForMissingRoute(): void
	{
		// Act
		$url = $this->urlHelper->routePath( 'nonexistent_route' );

		// Assert
		$this->assertNull( $url );
	}

	public function testRouteUrlReturnsNullForMissingRoute(): void
	{
		// Act
		$url = $this->urlHelper->routeUrl( 'nonexistent_route' );

		// Assert
		$this->assertNull( $url );
	}

	public function testRouteExistsReturnsTrueForExistingRoute(): void
	{
		// Arrange
		$this->router->get( '/users/:id', function() { return 'test'; } )
		              ->setName( 'user_profile' );

		// Act & Assert
		$this->assertTrue( $this->urlHelper->routeExists( 'user_profile' ) );
	}

	public function testRouteExistsReturnsFalseForMissingRoute(): void
	{
		// Act & Assert
		$this->assertFalse( $this->urlHelper->routeExists( 'nonexistent_route' ) );
	}

	public function testMagicMethodPathGeneration(): void
	{
		// Arrange
		$this->router->get( '/users/:id', function() { return 'test'; } )
		              ->setName( 'user_profile' );

		// Act
		$url = $this->urlHelper->userProfilePath( ['id' => 456] );

		// Assert
		$this->assertEquals( '/users/456', $url );
	}

	public function testMagicMethodUrlGeneration(): void
	{
		// Arrange
		$this->router->get( '/users/:id', function() { return 'test'; } )
		              ->setName( 'user_profile' );

		// Act
		$url = $this->urlHelper->userProfileUrl( ['id' => 456] );

		// Assert
		$this->assertEquals( 'https://example.com/users/456', $url );
	}

	public function testMagicMethodWithComplexRouteName(): void
	{
		// Arrange
		$this->router->get( '/admin/users/:id/posts/:post_id', function() { return 'test'; } )
		              ->setName( 'admin_user_posts' );

		// Act
		$url = $this->urlHelper->adminUserPostsPath( ['id' => 1, 'post_id' => 2] );

		// Assert
		$this->assertEquals( '/admin/users/1/posts/2', $url );
	}

	public function testMagicMethodThrowsExceptionForInvalidMethod(): void
	{
		// Expect
		$this->expectException( \BadMethodCallException::class );
		$this->expectExceptionMessage( "Method 'invalidMethod' not found in UrlHelper" );

		// Act
		$this->urlHelper->invalidMethod();
	}

	public function testGetAvailableRoutesReturnsNamedRoutes(): void
	{
		// Arrange
		$this->router->get( '/users', function() { return 'index'; } )
		              ->setName( 'users_index' );
		$this->router->post( '/users', function() { return 'create'; } )
		              ->setName( 'users_create' );

		// Act
		$routes = $this->urlHelper->getAvailableRoutes();

		// Assert
		$this->assertCount( 2, $routes );
		$this->assertEquals( 'users_index', $routes[0]['name'] );
		$this->assertEquals( 'GET', $routes[0]['method'] );
		$this->assertEquals( '/users', $routes[0]['path'] );
		$this->assertEquals( 'users_create', $routes[1]['name'] );
		$this->assertEquals( 'POST', $routes[1]['method'] );
		$this->assertEquals( '/users', $routes[1]['path'] );
	}

	public function testGetSetRouter(): void
	{
		// Arrange
		$newRouter = new Router();

		// Act
		$result = $this->urlHelper->setRouter( $newRouter );
		$retrievedRouter = $this->urlHelper->getRouter();

		// Assert
		$this->assertSame( $this->urlHelper, $result ); // Fluent interface
		$this->assertSame( $newRouter, $retrievedRouter );
	}

	public function testRouteWithoutParametersGeneratesCorrectUrl(): void
	{
		// Arrange
		$this->router->get( '/about', function() { return 'about'; } )
		              ->setName( 'about' );

		// Act
		$url = $this->urlHelper->routePath( 'about' );

		// Assert
		$this->assertEquals( '/about', $url );
	}

	public function testRouteWithMultipleParameters(): void
	{
		// Arrange
		$this->router->get( '/users/:id/posts/:post_id/comments/:comment_id', function() { return 'comment'; } )
		              ->setName( 'user_post_comment' );

		// Act
		$url = $this->urlHelper->routePath( 'user_post_comment', [
			'id' => 1,
			'post_id' => 2,
			'comment_id' => 3
		]);

		// Assert
		$this->assertEquals( '/users/1/posts/2/comments/3', $url );
	}

	public function testAbsoluteUrlWithoutBaseUrlInRegistry(): void
	{
		// Arrange - Temporarily override Base.Url with null
		$registry = Registry::getInstance();
		$originalBaseUrl = $registry->get( 'Base.Url' );
		$registry->set( 'Base.Url', null );

		$this->router->get( '/users/:id', function() { return 'test'; } )
		              ->setName( 'user_profile' );

		// Act
		$url = $this->urlHelper->routeUrl( 'user_profile', ['id' => 123] );

		// Restore original value
		$registry->set( 'Base.Url', $originalBaseUrl );

		// Assert - Should return relative URL when no base URL is available
		$this->assertEquals( '/users/123', $url );
	}
}