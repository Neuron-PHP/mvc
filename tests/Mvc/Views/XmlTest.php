<?php

namespace Mvc\Views;

use Neuron\Mvc\Views\Xml;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
	public function testRender()
	{
		$Xml = new Xml();
		
		$Data = [
			'key1' => 'value1',
			'key2' => 'value2',
			'nested' => [
				'item1' => 'nested value'
			]
		];
		
		$Result = $Xml->render( $Data );
		
		// Currently the implementation returns empty string
		$this->assertEquals( '', $Result );
	}
	
	public function testRenderWithEmptyData()
	{
		$Xml = new Xml();
		
		$Result = $Xml->render( [] );
		
		$this->assertEquals( '', $Result );
	}
}