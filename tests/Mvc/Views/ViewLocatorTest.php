<?php

namespace Mvc\Views;

use Neuron\Core\Registry\RegistryKeys;
use Neuron\Mvc\Views\ViewLocator;
use Neuron\Patterns\Registry;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class ViewLocatorTest extends TestCase
{
	protected function tearDown(): void
	{
		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, null );
		Registry::getInstance()->set( RegistryKeys::BASE_PATH, null );
		parent::tearDown();
	}

	public function testNormalizeAcceptsStringOrArray(): void
	{
		$this->assertEquals( [ '/var/views' ], ViewLocator::normalize( '/var/views/' ) );
		$this->assertEquals(
			[ '/site', '/package' ],
			ViewLocator::normalize( [ '/site/', '', '/package', '/site' ] )
		);
		$this->assertEquals( [], ViewLocator::normalize( null ) );
	}

	public function testLocatePrefersFirstRoot(): void
	{
		$root = vfsStream::setup( 'roots' );
		$site = vfsStream::newDirectory( 'site' )->at( $root );
		$package = vfsStream::newDirectory( 'package' )->at( $root );
		$siteBlog = vfsStream::newDirectory( 'blog' )->at( $site );
		$packageBlog = vfsStream::newDirectory( 'blog' )->at( $package );

		vfsStream::newFile( 'show.php' )->at( $siteBlog )->withContent( 'site' );
		vfsStream::newFile( 'show.php' )->at( $packageBlog )->withContent( 'package' );

		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, [
			vfsStream::url( 'roots/site' ),
			vfsStream::url( 'roots/package' ),
		] );

		$located = ( new ViewLocator() )->locate( 'blog/show.php' );

		$this->assertEquals( vfsStream::url( 'roots/site/blog/show.php' ), $located );
	}

	public function testLocateFallsThroughAndRejectsTraversal(): void
	{
		$root = vfsStream::setup( 'fallback' );
		$site = vfsStream::newDirectory( 'site' )->at( $root );
		$package = vfsStream::newDirectory( 'package' )->at( $root );
		vfsStream::newDirectory( 'blog' )->at( $package );
		vfsStream::newFile( 'show.php' )->at( $package->getChild( 'blog' ) )->withContent( 'package' );

		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, [
			vfsStream::url( 'fallback/site' ),
			vfsStream::url( 'fallback/package' ),
		] );

		$locator = new ViewLocator();

		$this->assertEquals(
			vfsStream::url( 'fallback/package/blog/show.php' ),
			$locator->locate( 'blog/show.php' )
		);
		$this->assertNull( $locator->locate( '../secret.php' ) );
		$this->assertNull( $locator->locate( '' ) );
	}

	public function testAppendPathDoesNotDuplicate(): void
	{
		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, '/site/views' );

		ViewLocator::appendPath( '/package/views' );
		ViewLocator::appendPath( '/package/views/' );

		$this->assertEquals(
			[ '/site/views', '/package/views' ],
			Registry::getInstance()->get( RegistryKeys::VIEWS_PATH )
		);
	}

	public function testPathsFallBackToBasePathResourcesViews(): void
	{
		Registry::getInstance()->set( RegistryKeys::VIEWS_PATH, null );
		Registry::getInstance()->set( RegistryKeys::BASE_PATH, '/app' );

		$this->assertEquals(
			[ '/app/resources/views' ],
			( new ViewLocator() )->paths()
		);
	}
}
