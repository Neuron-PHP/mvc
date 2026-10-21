<?php

namespace Tests\Mvc\Components\Fixtures;

use Neuron\Mvc\Components\IComponentProvider;
use Neuron\Mvc\IMvcApplication;

/**
 * Provider that blows up during registration.
 */
class BrokenProvider implements IComponentProvider
{
	public static function controllerPaths(): array
	{
		return [];
	}

	public static function viewPaths(): array
	{
		return [];
	}

	public static function register( IMvcApplication $app ): void
	{
		throw new \RuntimeException( 'nope' );
	}
}
