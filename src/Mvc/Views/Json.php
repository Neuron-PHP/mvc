<?php

namespace Neuron\Mvc\Views;

class Json implements IView
{
	public function render( array $Data ): string
	{
		return json_encode( $Data );
	}
}
