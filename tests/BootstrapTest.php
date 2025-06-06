<?php

namespace tests;

use PHPUnit\Framework\TestCase;

class BootstrapTest extends TestCase
{
	 public function testBootstrap()
	 {
		 $cwd = getcwd();
		 $App = Boot( 'examples/config' );
		 $this->assertTrue( is_object( $App ) );

		 Dispatch( $App );
	 }

	 public function testMissingConfig()
	 {
		 $cwd = getcwd();
		 $App = Boot( 'examples/non-there' );
		 $this->assertTrue( is_object( $App ) );

		 Dispatch( $App );
	 }
}
