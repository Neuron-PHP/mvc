<?php

namespace Neuron\Mvc\Events;

use Neuron\Events\IEvent;

/**
 * Event generated when a 500 error takes place.
 * Happens before the rendering of the error page.
 */
class Http500 implements IEvent
{
	public string $route;
	public string $exceptionType;
	public string $message;
	public string $file;
	public int $line;

	public function __construct( string $route, string $exceptionType, string $message, string $file, int $line )
	{
		$this->route = $route;
		$this->exceptionType = $exceptionType;
		$this->message = $message;
		$this->file = $file;
		$this->line = $line;
	}
}