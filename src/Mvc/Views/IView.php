<?php

namespace Neuron\Mvc\Views;

interface IView
{
	public function render( array $Data ) : string;
}
