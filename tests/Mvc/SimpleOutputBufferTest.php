<?php

namespace Tests\Mvc;

use PHPUnit\Framework\TestCase;

/**
 * Simple test to verify output buffer behavior
 */
class SimpleOutputBufferTest extends TestCase
{
	public function testInitialBufferLevel(): void
	{
		$level = ob_get_level();
		$this->assertGreaterThanOrEqual( 0, $level );

		// PHPUnit typically starts with level 1
	}

	public function testNoOutputBuffer(): void
	{
		// This test does nothing with output buffers
		$this->assertTrue( true );
	}

	public function testWithOutputBuffer(): void
	{
		$initialLevel = ob_get_level();

		ob_start();
		echo "test";
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertEquals( "test", $content );
		$this->assertEquals( $initialLevel, ob_get_level() );
	}
}