<?php

namespace Neuron\Mvc\Controllers;

class HttpCodes extends Base
{
	public function _404( array $Parameters )
	{
		return $this->renderHtml( $Parameters, '404' );
	}
}
