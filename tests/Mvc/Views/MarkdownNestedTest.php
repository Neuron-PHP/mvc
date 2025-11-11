<?php

namespace Mvc\Views;

use Neuron\Mvc\Views\Markdown;
use Neuron\Patterns\Registry;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class MarkdownNestedTest extends TestCase
{
	protected          $root;
	protected Markdown $markdown;

	protected function setUp(): void
	{
		parent::setUp();

		// Create virtual filesystem
		$this->root = vfsStream::setup( 'views', null, [
			'testcontroller' => [
				'page1.md' => '# Direct Page',
				'subfolder' => [
					'page2.md' => '# Nested Page',
					'deep' => [
						'page3.md' => '# Deep Nested Page'
					]
				]
			],
			'layouts' => [
				'default.php' => '<html><body><?= $content ?></body></html>'
			]
		]);

		$this->markdown = new Markdown();
		$this->markdown->setController( 'testcontroller' );
		$this->markdown->setPage( 'page1' );
		$this->markdown->setLayout( 'default' );

		// Setup registry
		Registry::getInstance()->set( "Views.Path", vfsStream::url( 'views' ) );
	}

	public function testFindMarkdownFileInDirectPath()
	{
		$reflection = new \ReflectionClass( $this->markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		$basePath = vfsStream::url( 'views/testcontroller' );
		$result = $method->invoke( $this->markdown, $basePath, 'page1' );

		$this->assertNotNull( $result );
		$this->assertStringEndsWith( 'page1.md', $result );
	}

	public function testFindMarkdownFileInSubfolder()
	{
		$reflection = new \ReflectionClass( $this->markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		$basePath = vfsStream::url( 'views/testcontroller' );
		$result = $method->invoke( $this->markdown, $basePath, 'page2' );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'subfolder', $result );
		$this->assertStringEndsWith( 'page2.md', $result );
	}

	public function testFindMarkdownFileInDeepNestedFolder()
	{
		$reflection = new \ReflectionClass( $this->markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		$basePath = vfsStream::url( 'views/testcontroller' );
		$result = $method->invoke( $this->markdown, $basePath, 'page3' );

		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'deep', $result );
		$this->assertStringEndsWith( 'page3.md', $result );
	}

	public function testFindMarkdownFileReturnsNullForNonExistentFile()
	{
		$reflection = new \ReflectionClass( $this->markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		$basePath = vfsStream::url( 'views/testcontroller' );
		$result = $method->invoke( $this->markdown, $basePath, 'nonexistent' );

		$this->assertNull( $result );
	}

	public function testFindMarkdownFileReturnsNullForInvalidBasePath()
	{
		$reflection = new \ReflectionClass( $this->markdown );
		$method = $reflection->getMethod( 'findMarkdownFile' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->markdown, '/invalid/path', 'page1' );

		$this->assertNull( $result );
	}

	public function testRenderWithNestedMarkdownFile()
	{
		$this->markdown->setPage( 'page2' );

		$result = $this->markdown->render( [] );

		$this->assertStringContainsString( '<h1>Nested Page</h1>', $result );
		$this->assertStringContainsString( '<html>', $result );
		$this->assertStringContainsString( '</html>', $result );
	}
}

