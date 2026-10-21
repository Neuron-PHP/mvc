<?php

namespace Tests\Mvc\Components\Fixtures;

use Neuron\Mvc\Components\IComponentProvider;
use Neuron\Mvc\Components\INavigationProvider;
use Neuron\Mvc\IMvcApplication;

/**
 * Provider whose declarations are set by the test and which records
 * register() calls.
 */
class RecordingProvider implements IComponentProvider, INavigationProvider
{
	public static array $controllerPaths = [];
	public static array $viewPaths = [];
	public static array $navigation = [];
	public static array $registeredWith = [];

	public static function reset(): void
	{
		self::$controllerPaths = [];
		self::$viewPaths = [];
		self::$navigation = [];
		self::$registeredWith = [];
	}

	public static function controllerPaths(): array
	{
		return self::$controllerPaths;
	}

	public static function viewPaths(): array
	{
		return self::$viewPaths;
	}

	public static function register( IMvcApplication $app ): void
	{
		self::$registeredWith[] = $app;
	}

	public static function navigation(): array
	{
		return self::$navigation;
	}
}
