<?php

namespace Tests;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

// Import the namespaced function
use function Neuron\Mvc\partial;

class PartialTest extends TestCase
{
	private string $TempDir;
	private array $OriginalRegistry;
	private array $CreatedFiles = [];
	
	protected function setUp(): void
	{
		parent::setUp();
		
		// Create temp directory structure
		$this->TempDir = sys_get_temp_dir() . '/partial_test_' . uniqid();
		mkdir( $this->TempDir );
		mkdir( $this->TempDir . '/resources' );
		mkdir( $this->TempDir . '/resources/views' );
		mkdir( $this->TempDir . '/resources/views/shared' );
		
		// Store original registry values
		$this->OriginalRegistry = [
			'Views.Path' => Registry::getInstance()->get( 'Views.Path' ),
			'Base.Path' => Registry::getInstance()->get( 'Base.Path' )
		];
		
		// Set Base.Path to temp directory
		Registry::getInstance()->set( 'Views.Path', null );
		Registry::getInstance()->set( 'Base.Path', $this->TempDir );
	}
	
	protected function tearDown(): void
	{
		// Restore original registry values
		foreach( $this->OriginalRegistry as $Key => $Value )
		{
			Registry::getInstance()->set( $Key, $Value );
		}
		
		// Clean up created files
		foreach( $this->CreatedFiles as $File )
		{
			if( file_exists( $File ) )
			{
				unlink( $File );
			}
		}
		
		// Clean up temp directories
		if( is_dir( $this->TempDir . '/resources/views/shared' ) )
		{
			rmdir( $this->TempDir . '/resources/views/shared' );
		}
		if( is_dir( $this->TempDir . '/resources/views' ) )
		{
			rmdir( $this->TempDir . '/resources/views' );
		}
		if( is_dir( $this->TempDir . '/resources' ) )
		{
			rmdir( $this->TempDir . '/resources' );
		}
		
		// Clean up custom directories if created
		if( is_dir( $this->TempDir . '/custom/views/shared' ) )
		{
			rmdir( $this->TempDir . '/custom/views/shared' );
		}
		if( is_dir( $this->TempDir . '/custom/views' ) )
		{
			rmdir( $this->TempDir . '/custom/views' );
		}
		if( is_dir( $this->TempDir . '/custom' ) )
		{
			rmdir( $this->TempDir . '/custom' );
		}
		
		if( is_dir( $this->TempDir ) )
		{
			rmdir( $this->TempDir );
		}
		
		parent::tearDown();
	}
	
	/**
	 * Helper to create a partial file
	 */
	private function createPartial( string $Name, string $Content, ?string $Path = null ): void
	{
		$Dir = $Path ?? $this->TempDir . '/resources/views/shared';
		$File = $Dir . '/_' . $Name . '.php';
		file_put_contents( $File, $Content );
		$this->CreatedFiles[] = $File;
	}
	
	/**
	 * Helper to capture output from Partial function
	 */
	private function capturePartialOutput( string $Name, array $Data = [] ): string
	{
		ob_start();
		partial( $Name, $Data );
		return ob_get_clean();
	}
	
	/**
	 * Test loading a basic partial successfully
	 */
	public function testLoadBasicPartial()
	{
		$this->createPartial( 'header', 'Site Header Content' );
		
		$Result = $this->capturePartialOutput( 'header' );
		
		$this->assertEquals( 'Site Header Content', $Result );
	}
	
	/**
	 * Test loading a partial that doesn't exist
	 */
	public function testPartialNotFound()
	{
		$this->expectException( NotFound::class );
		$this->expectExceptionMessage( 'Partial not found' );
		
		partial( 'nonexistent' );
	}
	
	/**
	 * Test using custom Views.Path from Registry
	 */
	public function testCustomViewsPath()
	{
		// Create custom views directory
		mkdir( $this->TempDir . '/custom' );
		mkdir( $this->TempDir . '/custom/views' );
		mkdir( $this->TempDir . '/custom/views/shared' );
		
		// Create partial in custom location
		$this->createPartial( 'custom', 'Custom View Path Content', $this->TempDir . '/custom/views/shared' );
		
		// Set custom views path
		Registry::getInstance()->set( 'Views.Path', $this->TempDir . '/custom/views' );
		
		$Result = $this->capturePartialOutput( 'custom' );
		
		$this->assertEquals( 'Custom View Path Content', $Result );
	}
	
	/**
	 * Test default path fallback when Views.Path not set
	 */
	public function testDefaultPathFallback()
	{
		// Ensure Views.Path is null to test fallback
		Registry::getInstance()->set( 'Views.Path', null );
		
		// Create partial in default location
		$this->createPartial( 'default', 'Default Path Content' );
		
		$Result = $this->capturePartialOutput( 'default' );
		
		$this->assertEquals( 'Default Path Content', $Result );
	}
	
	/**
	 * Test partial with PHP code execution
	 */
	public function testPartialWithPhpCode()
	{
		$this->createPartial( 'with_php', '<?php echo "Hello "; ?>World' );
		
		$Result = $this->capturePartialOutput( 'with_php' );
		
		$this->assertEquals( 'Hello World', $Result );
	}
	
	/**
	 * Test partial with variables in scope
	 */
	public function testPartialWithVariables()
	{
		$Content = '<?php $title = "My Title"; echo "<h1>$title</h1>"; ?>';
		$this->createPartial( 'with_vars', $Content );
		
		$Result = $this->capturePartialOutput( 'with_vars' );
		
		$this->assertEquals( '<h1>My Title</h1>', $Result );
	}
	
	/**
	 * Test loading multiple partials
	 */
	public function testMultiplePartials()
	{
		$this->createPartial( 'nav', '<nav>Navigation</nav>' );
		$this->createPartial( 'sidebar', '<aside>Sidebar</aside>' );
		
		$Nav = $this->capturePartialOutput( 'nav' );
		$Sidebar = $this->capturePartialOutput( 'sidebar' );
		
		$this->assertEquals( '<nav>Navigation</nav>', $Nav );
		$this->assertEquals( '<aside>Sidebar</aside>', $Sidebar );
	}
	
	/**
	 * Test empty partial file
	 */
	public function testEmptyPartial()
	{
		$this->createPartial( 'empty', '' );
		
		$Result = $this->capturePartialOutput( 'empty' );
		
		$this->assertEquals( '', $Result );
	}
	
	/**
	 * Test partial with HTML and PHP mixed
	 */
	public function testMixedHtmlPhpPartial()
	{
		$Content = '<div class="user">
	<h2><?php echo "User Profile"; ?></h2>
	<p>Name: <?php echo "John Doe"; ?></p>
	<p>Status: <?php echo date("Y-m-d"); ?></p>
</div>';
		
		$this->createPartial( 'user_profile', $Content );
		
		$Result = $this->capturePartialOutput( 'user_profile' );
		
		// Check that PHP was executed
		$this->assertStringContainsString( '<h2>User Profile</h2>', $Result );
		$this->assertStringContainsString( 'Name: John Doe', $Result );
		$this->assertStringContainsString( 'Status: ' . date('Y-m-d'), $Result );
	}
	
	/**
	 * Test partial with loops and conditionals
	 */
	public function testPartialWithControlStructures()
	{
		$Content = '<?php
$items = ["Apple", "Banana", "Orange"];
?>
<ul>
<?php foreach( $items as $item ): ?>
	<li><?php echo $item; ?></li>
<?php endforeach; ?>
</ul>
<?php if( count($items) > 0 ): ?>
<p>Total items: <?php echo count($items); ?></p>
<?php endif; ?>';
		
		$this->createPartial( 'list', $Content );
		
		$Result = $this->capturePartialOutput( 'list' );
		
		// Verify the output
		$this->assertStringContainsString( '<li>Apple</li>', $Result );
		$this->assertStringContainsString( '<li>Banana</li>', $Result );
		$this->assertStringContainsString( '<li>Orange</li>', $Result );
		$this->assertStringContainsString( 'Total items: 3', $Result );
	}
	
	/**
	 * Test partial with output already started
	 */
	public function testPartialWithExistingOutput()
	{
		$this->createPartial( 'test', 'Partial Content' );
		
		// Start output buffering (simulating existing output)
		ob_start();
		echo "Before partial ";
		
		// Partial now echoes directly
		partial( 'test' );
		
		echo " After partial";
		$FullOutput = ob_get_clean();
		
		// Check the full output
		$this->assertEquals( 'Before partial Partial Content After partial', $FullOutput );
	}
	
	/**
	 * Test partial path with special characters in name
	 */
	public function testPartialWithSpecialCharactersInName()
	{
		$this->createPartial( 'user-profile_widget', 'User Profile Widget' );
		
		$Result = $this->capturePartialOutput( 'user-profile_widget' );
		
		$this->assertEquals( 'User Profile Widget', $Result );
	}
	
	/**
	 * Test that partial function properly handles file paths
	 */
	public function testPartialPathConstruction()
	{
		// Set a base path with trailing slash
		Registry::getInstance()->set( 'Base.Path', $this->TempDir . '/' );
		Registry::getInstance()->set( 'Views.Path', null );
		
		$this->createPartial( 'pathtest', 'Path Test' );
		
		// Should handle double slashes properly
		$Result = $this->capturePartialOutput( 'pathtest' );
		
		$this->assertEquals( 'Path Test', $Result );
	}
	
	/**
	 * Test using the existing _test.php partial from the real project
	 */
	public function testRealTestPartial()
	{
		// Set Views.Path to the real project views
		Registry::getInstance()->set( 'Views.Path', null );
		Registry::getInstance()->set( 'Base.Path', dirname( __DIR__ ) );
		
		// This should load the actual _test.php file from resources/views/shared/
		$Result = $this->capturePartialOutput( 'test' );
		
		$this->assertEquals( "This is a test.\n", $Result );
	}
}
