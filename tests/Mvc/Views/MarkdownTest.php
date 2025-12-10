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
}
