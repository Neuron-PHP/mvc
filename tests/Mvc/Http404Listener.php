<?php

namespace Mvc;
use Neuron\Events\IListener;

class Http404Listener implements IListener
{
	public string $State;

	public function event( $Event )
	{
		$this->State = get_class( $Event );
	}
}
