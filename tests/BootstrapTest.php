<?php

namespace Tests;

use Neuron\Mvc\Application;
use Neuron\Patterns\Registry;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

// Import the namespaced functions
use function Neuron\Mvc\boot;
use function Neuron\Mvc\dispatch;
use function Neuron\Mvc\ClearExpiredCache;

class BootstrapTest extends TestCase
{
	private $Root;
	private $OriginalGet;
	private $OriginalServer;
	private $OriginalEnv;
	
	protected function setUp(): void
	{
		parent::setUp();
		
		// Create virtual filesystem
		$this->Root = vfsStream::setup( 'test' );
		
		// Store original superglobals
		$this->OriginalGet = $_GET ?? [];
		$this->OriginalServer = $_SERVER ?? [];
		$this->OriginalEnv = $_ENV ?? [];
		
		// Clear registry
		Registry::getInstance()->set( 'Settings', null );
		Registry::getInstance()->set( 'Base.Path', null );
	}
	
	protected function tearDown(): void
	{
		// Restore original superglobals
		$_GET = $this->OriginalGet;
		$_SERVER = $this->OriginalServer;
		$_ENV = $this->OriginalEnv;
		
		// Clear environment variable
		putenv( 'SYSTEM_BASE_PATH' );
		
		parent::tearDown();
	}
	
	/**
	 * Test Boot function with valid config file
	 */
	public function testBootWithValidConfig()
	{
		// Create config.yaml
		$ConfigContent = <<<YAML
system:
  base_path: /app
  environment: test

database:
  host: localhost
  port: 3306
YAML;
		
		vfsStream::newFile( 'config.yaml' )
			->at( $this->Root )
			->setContent( $ConfigContent );
		
		// Create version.json
		$VersionContent = json_encode([
			'major' => 1,
			'minor' => 2,
			'patch' => 3
		]);
		
		vfsStream::newFile( '.version.json' )
			->at( $this->Root )
			->setContent( $VersionContent );
		
		// Mock base path
		$BasePath = vfsStream::url( 'test' );
		
		// Update config to use virtual filesystem path
		$ConfigContent = str_replace( '/app', $BasePath, $ConfigContent );
		$this->Root->getChild( 'config.yaml' )->setContent( $ConfigContent );
		
		// Boot the application
		$App = boot( $BasePath );
		
		// Assertions
		$this->assertInstanceOf( Application::class, $App );
		$this->assertEquals( '1.2.3', $App->getVersion() );
		$this->assertNotNull( Registry::getInstance()->get( 'Settings' ) );
	}
	
	/**
	 * Test Boot function with missing config file (falls back to environment)
	 */
	public function testBootWithMissingConfig()
	{
		// Set environment variable
		$BasePath = vfsStream::url( 'test' );
		putenv( "SYSTEM_BASE_PATH=$BasePath" );
		
		// Create version.json
		$VersionContent = json_encode([
			'major' => 2,
			'minor' => 0,
			'patch' => 0
		]);
		
		vfsStream::newFile( '.version.json' )
			->at( $this->Root )
			->setContent( $VersionContent );
		
		// Boot with non-existent config path - should now use environment variable
		$App = boot( vfsStream::url( 'test/nonexistent' ) );
		
		// Should successfully create application with environment path
		$this->assertInstanceOf( Application::class, $App );
		$this->assertEquals( '2.0.0', $App->getVersion() );
	}
	
	/**
	 * Test Boot function defaults to current directory when no env var
	 */
	public function testBootWithDefaultBasePath()
	{
		// Clear environment variable
		putenv( 'SYSTEM_BASE_PATH' );
		
		// Create version.json in virtual filesystem root
		$VersionContent = json_encode([
			'major' => 3,
			'minor' => 1,
			'patch' => 4
		]);
		
		vfsStream::newFile( '.version.json' )
			->at( $this->Root )
			->setContent( $VersionContent );
		
		// Create a temporary directory for this test
		$TempDir = sys_get_temp_dir() . '/neuron_test_' . uniqid();
		mkdir( $TempDir );
		$TempVersionFile = $TempDir . '/.version.json';
		file_put_contents( $TempVersionFile, $VersionContent );
		
		// Save original CWD and change to temp directory
		$OriginalCwd = getcwd();
		chdir( $TempDir );
		
		try
		{
			// Boot with non-existent config path - will use current directory
			$App = boot( vfsStream::url( 'test/nonexistent' ) );
			
			// Should successfully create application
			$this->assertInstanceOf( Application::class, $App );
			$this->assertEquals( '3.1.4', $App->getVersion() );
		}
		finally
		{
			// Restore original directory
			chdir( $OriginalCwd );
			// Clean up temp files
			@unlink( $TempVersionFile );
			@rmdir( $TempDir );
		}
	}
	
	/**
	 * Test Dispatch function with GET request
	 * Note: The Filter classes read directly from superglobals which are
	 * difficult to mock in PHPUnit. This test verifies the function is called.
	 */
	public function testDispatchGetRequest()
	{
		// Create mock application
		$App = $this->createMock( Application::class );
		$App->expects( $this->once() )
			->method( 'run' )
			->with( $this->callback( function( $Params ) {
				return is_array( $Params ) &&
					   isset( $Params['type'] ) &&
					   isset( $Params['route'] ) &&
					   in_array( $Params['type'], ['GET', 'POST'] );
			} ) );
		
		// Dispatch - will use actual superglobals
		dispatch( $App );
	}
	
	/**
	 * Test Dispatch function is called correctly
	 */
	public function testDispatchFunctionIsCalled()
	{
		// Create mock application
		$App = $this->createMock( Application::class );
		$App->expects( $this->once() )
			->method( 'run' )
			->with( $this->isType( 'array' ) );
		
		// Dispatch
		dispatch( $App );
	}
	
	/**
	 * Test Dispatch function parameters structure
	 */
	public function testDispatchParametersStructure()
	{
		// Create mock application
		$App = $this->createMock( Application::class );
		$App->expects( $this->once() )
			->method( 'run' )
			->with( $this->callback( function( $Params ) {
				// Verify the structure
				return is_array( $Params ) &&
					   array_key_exists( 'type', $Params ) &&
					   array_key_exists( 'route', $Params ) &&
					   is_string( $Params['type'] ) &&
					   is_string( $Params['route'] );
			} ) );
		
		// Dispatch
		dispatch( $App );
	}
	
	/**
	 * Test Dispatch function with exception handling
	 */
	public function testDispatchWithException()
	{
		// Create mock application that throws exception
		$App = $this->createMock( Application::class );
		$App->expects( $this->once() )
			->method( 'run' )
			->willThrowException( new \Exception( 'Test exception' ) );
		
		// Capture output
		ob_start();
		dispatch( $App );
		$Output = ob_get_clean();
		
		// Should output 'Ouch.' when exception is caught
		$this->assertEquals( 'Ouch.', $Output );
	}
	
	/**
	 * Test ClearExpiredCache function
	 */
	public function testClearExpiredCache()
	{
		// Create mock application
		$App = $this->createMock( Application::class );
		$App->expects( $this->once() )
			->method( 'clearExpiredCache' )
			->willReturn( 42 );  // Mock cleared 42 entries
		
		// Clear cache
		$ClearedCount = ClearExpiredCache( $App );
		
		// Assert return value
		$this->assertEquals( 42, $ClearedCount );
	}
	
	/**
	 * Test ClearExpiredCache with no expired entries
	 */
	public function testClearExpiredCacheNoEntries()
	{
		// Create mock application
		$App = $this->createMock( Application::class );
		$App->expects( $this->once() )
			->method( 'clearExpiredCache' )
			->willReturn( 0 );  // No entries cleared
		
		// Clear cache
		$ClearedCount = ClearExpiredCache( $App );
		
		// Assert return value
		$this->assertEquals( 0, $ClearedCount );
	}
	
	/**
	 * Keep original tests for backward compatibility
	 */
	public function testLegacyBootstrap()
	{
		$App = boot( 'examples/config' );
		$this->assertInstanceOf( Application::class, $App );
	}
	
	public function testLegacyMissingConfig()
	{
		// With the fix, this now falls back to environment/default path
		// Since there's a .version.json in current directory, it will succeed
		$App = boot( 'examples/non-there' );
		$this->assertInstanceOf( Application::class, $App );
	}
}
