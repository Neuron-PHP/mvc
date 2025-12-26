<?php

namespace Mvc\Views;

use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Patterns\Registry;
use Neuron\Routing\Router;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class MarkdownTest extends TestCase
{
	protected function setUp(): void
	{
		// Clear any existing registry values
		Registry::getInstance()->set( "Views.Path", null );
		Registry::getInstance()->set( "ViewCache", null );
	}

	protected function tearDown(): void
	{
		// Clean up after test
		Registry::getInstance()->set( "Views.Path", null );
		Registry::getInstance()->set( "ViewCache", null );
	}

	public function testRender()
	{
		$Base = new Base();

		Registry::getInstance()->set( "Views.Path", "examples/views" );

		$Result = $Base->renderMarkdown(
			HttpResponseStatus::OK,
			[
				'var_one' => 'test variable',
				'two'     => 2,
				'three'   => 3
			]
		);

		$this->assertStringContainsString( "<h2>Header 2</h2>", $Result );
		$this->assertStringContainsString( "This is a test.", $Result );
	}

	public function testFindMarkdownFileWithNonExistentDirectory()
	{
		$markdown = new \Neuron\Mvc\Views\Markdown();

		$reflection = new \ReflectionClass( $markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		$result = $method->invoke( $markdown, '/nonexistent/path', 'test' );

		$this->assertNull( $result );
	}

	public function testFindMarkdownFileWithDirectoryTraversal()
	{
		$markdown = new \Neuron\Mvc\Views\Markdown();

		$reflection = new \ReflectionClass( $markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		// Test directory traversal attack
		$result = $method->invoke( $markdown, '/tmp', '../etc/passwd' );

		$this->assertNull( $result );
	}

	public function testFindMarkdownFileWithBackslashes()
	{
		$markdown = new \Neuron\Mvc\Views\Markdown();

		$reflection = new \ReflectionClass( $markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		// Create a temporary directory and file for testing
		$tmpDir = sys_get_temp_dir() . '/neuron_markdown_test_' . uniqid();
		mkdir( $tmpDir, 0777, true );
		mkdir( $tmpDir . '/subdir', 0777, true );
		file_put_contents( $tmpDir . '/subdir/test.md', '# Test' );

		// Test with backslash path separator (Windows style)
		$result = $method->invoke( $markdown, $tmpDir, 'subdir\test' );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'subdir/test.md', $result );

		// Clean up
		unlink( $tmpDir . '/subdir/test.md' );
		rmdir( $tmpDir . '/subdir' );
		rmdir( $tmpDir );
	}

	public function testFindMarkdownFileExists()
	{
		$markdown = new \Neuron\Mvc\Views\Markdown();

		$reflection = new \ReflectionClass( $markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		// Create a temporary directory and file for testing
		$tmpDir = sys_get_temp_dir() . '/neuron_markdown_test_' . uniqid();
		mkdir( $tmpDir, 0777, true );
		file_put_contents( $tmpDir . '/test.md', '# Test' );

		$result = $method->invoke( $markdown, $tmpDir, 'test' );

		$this->assertNotNull( $result );
		$this->assertStringEndsWith( '/test.md', $result );

		// Clean up
		unlink( $tmpDir . '/test.md' );
		rmdir( $tmpDir );
	}

	public function testFindMarkdownFileNotFound()
	{
		$markdown = new \Neuron\Mvc\Views\Markdown();

		$reflection = new \ReflectionClass( $markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		// Create a temporary directory without the file
		$tmpDir = sys_get_temp_dir() . '/neuron_markdown_test_' . uniqid();
		mkdir( $tmpDir, 0777, true );

		$result = $method->invoke( $markdown, $tmpDir, 'nonexistent' );

		$this->assertNull( $result );

		// Clean up
		rmdir( $tmpDir );
	}

	public function testGetCommonmarkConverter()
	{
		$markdown = new \Neuron\Mvc\Views\Markdown();

		$reflection = new \ReflectionClass( $markdown );
		$method = $reflection->getMethod( 'getCommonmarkConverter' );
		$method->setAccessible( true );

		$converter = $method->invoke( $markdown );

		$this->assertInstanceOf( \League\CommonMark\MarkdownConverter::class, $converter );
	}

	public function testRenderWithFallbackViewsPath()
	{
		// Don't set Views.Path to test fallback to Base.Path
		// The code will append /resources/views to Base.Path, but our views are at examples/views
		// So we need to create a temp structure that matches
		$tmpDir = sys_get_temp_dir() . '/neuron_markdown_base_test_' . uniqid();
		mkdir( $tmpDir, 0777, true );
		mkdir( $tmpDir . '/resources/views/base', 0777, true );
		mkdir( $tmpDir . '/resources/views/layouts', 0777, true );

		// Copy test files
		copy( 'examples/views/base/index.md', $tmpDir . '/resources/views/base/index.md' );
		copy( 'examples/views/layouts/default.php', $tmpDir . '/resources/views/layouts/default.php' );

		Registry::getInstance()->set( "Base.Path", $tmpDir );
		Registry::getInstance()->set( "Views.Path", null );

		$markdown = new \Neuron\Mvc\Views\Markdown();
		$markdown->setController( 'Base' );
		$markdown->setPage( 'index' );
		$markdown->setLayout( 'default' );

		$Result = $markdown->render( [] );

		$this->assertStringContainsString( "<h2>Header 2</h2>", $Result );

		// Clean up
		unlink( $tmpDir . '/resources/views/base/index.md' );
		unlink( $tmpDir . '/resources/views/layouts/default.php' );
		rmdir( $tmpDir . '/resources/views/base' );
		rmdir( $tmpDir . '/resources/views/layouts' );
		rmdir( $tmpDir . '/resources/views' );
		rmdir( $tmpDir . '/resources' );
		rmdir( $tmpDir );
	}

	public function testRenderThrowsExceptionForMissingLayout()
	{
		$this->expectException( \Neuron\Core\Exceptions\NotFound::class );
		$this->expectExceptionMessage( 'View notfound:' );

		Registry::getInstance()->set( "Views.Path", "examples/views" );

		$markdown = new \Neuron\Mvc\Views\Markdown();
		$markdown->setController( 'Base' );
		$markdown->setPage( 'markdown' );
		$markdown->setLayout( 'nonexistent_layout' );

		$markdown->render( [] );
	}

	public function testRenderWithCaching()
	{
		Registry::getInstance()->set( "Views.Path", "examples/views" );

		// Create a mock ViewCache
		$mockCache = $this->createMock( \Neuron\Mvc\Cache\ViewCache::class );

		// Configure mock to be enabled
		$mockCache->method( 'isEnabled' )
			->willReturn( true );

		// Configure mock to generate a key
		$mockCache->method( 'generateKey' )
			->willReturn( 'test_cache_key' );

		// Cache miss, should call get() and return null, then call set()
		$mockCache->expects( $this->once() )
			->method( 'get' )
			->with( 'test_cache_key' )
			->willReturn( null );

		$mockCache->expects( $this->once() )
			->method( 'set' )
			->with(
				'test_cache_key',
				$this->stringContains( '<h2>Header 2</h2>' )
			);

		Registry::getInstance()->set( "ViewCache", $mockCache );

		$markdown = new \Neuron\Mvc\Views\Markdown();
		$markdown->setController( 'Base' );
		$markdown->setPage( 'index' );
		$markdown->setLayout( 'default' );
		$markdown->setCacheEnabled( true );

		$result = $markdown->render( [] );

		$this->assertStringContainsString( "<h2>Header 2</h2>", $result );
	}

	public function testRenderWithCacheHit()
	{
		Registry::getInstance()->set( "Views.Path", "examples/views" );

		// Create a mock ViewCache that returns cached content
		$mockCache = $this->createMock( \Neuron\Mvc\Cache\ViewCache::class );

		$cachedContent = '<html><body><p>Cached content</p></body></html>';

		// Configure mock to be enabled
		$mockCache->method( 'isEnabled' )
			->willReturn( true );

		// Configure mock to generate a key
		$mockCache->method( 'generateKey' )
			->willReturn( 'test_cache_key' );

		$mockCache->expects( $this->once() )
			->method( 'get' )
			->with( 'test_cache_key' )
			->willReturn( $cachedContent );

		// set() should not be called since we got a cache hit
		$mockCache->expects( $this->never() )
			->method( 'set' );

		Registry::getInstance()->set( "ViewCache", $mockCache );

		$markdown = new \Neuron\Mvc\Views\Markdown();
		$markdown->setController( 'Base' );
		$markdown->setPage( 'index' );
		$markdown->setLayout( 'default' );
		$markdown->setCacheEnabled( true );

		$result = $markdown->render( [] );

		$this->assertEquals( $cachedContent, $result );
	}

	public function testRenderThrowsExceptionForMissingView()
	{
		$this->expectException( \Neuron\Core\Exceptions\NotFound::class );
		$this->expectExceptionMessage( 'View notfound: nonexistent.md' );

		Registry::getInstance()->set( "Views.Path", "examples/views" );

		$markdown = new \Neuron\Mvc\Views\Markdown();
		$markdown->setController( 'Base' );
		$markdown->setPage( 'nonexistent' );
		$markdown->setLayout( 'default' );

		$markdown->render( [] );
	}
}
