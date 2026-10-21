<?php

namespace Neuron\Mvc\Components;

use Neuron\Core\Registry\RegistryKeys;
use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;
use Neuron\Log\Log;
use Neuron\Mvc\IMvcApplication;
use Neuron\Mvc\Views\ViewLocator;
use Neuron\Patterns\Registry;

/**
 * Finds installed web components and applies them to an application.
 *
 * Discovery reads Composer's vendor/composer/installed.json (and the root
 * composer.json) for `extra.neuron.mvc-provider`, the same convention the
 * CLI uses for `cli-provider`. Loading hands each provider's controller
 * directories and view roots to the application and then calls its
 * register() hook. Loaded provider classes are published under
 * RegistryKeys::MVC_COMPONENTS so hosts can query them later (navigation).
 *
 * A provider that throws during loading is logged and skipped; one broken
 * component must not take the site down.
 *
 * @package Neuron\Mvc\Components
 */
class ComponentLoader
{
	public const EXTRA_KEY = 'mvc-provider';

	private IFileSystem $_fs;

	public function __construct( ?IFileSystem $fs = null )
	{
		$this->_fs = $fs ?? new RealFileSystem();
	}

	/**
	 * Provider classes declared by installed packages, in installation
	 * order, root project last. Only classes that exist and implement
	 * IComponentProvider are returned.
	 *
	 * @param string|null $basePath Application base path; its vendor/ is
	 *        checked first, then the working directory's, then this
	 *        package's own ancestors.
	 * @return list<class-string<IComponentProvider>>
	 */
	public function discover( ?string $basePath = null ): array
	{
		$classes = [];

		$vendorDir = $this->findVendorDirectory( $basePath );

		if( $vendorDir !== null )
		{
			foreach( $this->installedPackages( $vendorDir ) as $package )
			{
				array_push( $classes, ...$this->providersIn( $package ) );
			}

			$root = $this->readJson( dirname( $vendorDir ) . '/composer.json' );

			if( $root !== null )
			{
				array_push( $classes, ...$this->providersIn( $root ) );
			}
		}

		$valid = [];

		foreach( $classes as $class )
		{
			$class = ltrim( $class, '\\' );

			if( in_array( $class, $valid, true ) )
			{
				continue;
			}

			if( !class_exists( $class ) )
			{
				Log::warning( "Web component provider {$class} is declared but the class does not exist." );
				continue;
			}

			if( !is_subclass_of( $class, IComponentProvider::class ) )
			{
				Log::warning( "Web component provider {$class} does not implement " . IComponentProvider::class . '.' );
				continue;
			}

			$valid[] = $class;
		}

		return $valid;
	}

	/**
	 * Apply providers to the application and record which ones loaded.
	 *
	 * @param IMvcApplication $app
	 * @param list<class-string<IComponentProvider>> $providers
	 * @param list<string> $disabled Provider classes to skip
	 * @return list<class-string<IComponentProvider>> Providers actually loaded
	 */
	public function load( IMvcApplication $app, array $providers, array $disabled = [] ): array
	{
		$disabled = array_map( fn( $class ) => ltrim( (string)$class, '\\' ), $disabled );
		$loaded = [];

		foreach( $providers as $class )
		{
			$class = ltrim( $class, '\\' );

			if( in_array( $class, $disabled, true ) )
			{
				Log::debug( "Web component {$class} disabled by configuration" );
				continue;
			}

			try
			{
				foreach( $class::controllerPaths() as $entry )
				{
					$app->addControllerPath( (string)( $entry['path'] ?? '' ), (string)( $entry['namespace'] ?? '' ) );
				}

				foreach( $class::viewPaths() as $viewPath )
				{
					ViewLocator::appendPath( (string)$viewPath );
				}

				$class::register( $app );

				$loaded[] = $class;

				Log::debug( "Loaded web component {$class}" );
			}
			catch( \Throwable $e )
			{
				Log::error( "Web component {$class} failed to load: {$e->getMessage()}" );
			}
		}

		Registry::getInstance()->set( RegistryKeys::MVC_COMPONENTS, $loaded );

		return $loaded;
	}

	/**
	 * Providers the running application loaded.
	 *
	 * @return list<class-string<IComponentProvider>>
	 */
	public static function loaded(): array
	{
		$loaded = Registry::getInstance()->get( RegistryKeys::MVC_COMPONENTS );

		return is_array( $loaded ) ? array_values( $loaded ) : [];
	}

	/**
	 * Navigation items from every loaded INavigationProvider, optionally
	 * for one group, sorted by order then label. Each item also carries
	 * `provider` (the class it came from). Hosts should still drop items
	 * whose route is not registered.
	 *
	 * @param string|null $group
	 * @return list<array{group:string,label:string,route:string,icon:?string,order:int,provider:string}>
	 */
	public static function navigation( ?string $group = null ): array
	{
		$items = [];

		foreach( self::loaded() as $class )
		{
			if( !is_subclass_of( $class, INavigationProvider::class ) )
			{
				continue;
			}

			try
			{
				$declared = $class::navigation();
			}
			catch( \Throwable $e )
			{
				Log::error( "Web component {$class} navigation() threw: {$e->getMessage()}" );
				continue;
			}

			foreach( $declared as $item )
			{
				if( !is_array( $item ) || empty( $item['label'] ) || empty( $item['route'] ) )
				{
					continue;
				}

				$itemGroup = (string)( $item['group'] ?? 'system' );

				if( $group !== null && $itemGroup !== $group )
				{
					continue;
				}

				$items[] = [
					'group'    => $itemGroup,
					'label'    => (string)$item['label'],
					'route'    => (string)$item['route'],
					'icon'     => isset( $item['icon'] ) ? (string)$item['icon'] : null,
					'order'    => (int)( $item['order'] ?? 100 ),
					'provider' => $class,
				];
			}
		}

		usort( $items, fn( array $a, array $b ) => [ $a['order'], $a['label'] ] <=> [ $b['order'], $b['label'] ] );

		return $items;
	}

	/**
	 * @param string|null $basePath
	 * @return string|null
	 */
	private function findVendorDirectory( ?string $basePath ): ?string
	{
		$candidates = [];

		if( is_string( $basePath ) && $basePath !== '' )
		{
			$candidates[] = rtrim( $basePath, '/' ) . '/vendor';
		}

		$cwd = $this->_fs->getcwd();

		if( $cwd !== false )
		{
			$candidates[] = $cwd . '/vendor';
		}

		// mvc installed as a dependency: vendor/neuron-php/mvc/src/Mvc/Components
		$candidates[] = dirname( __DIR__, 5 );
		// mvc checked out on its own (development)
		$candidates[] = dirname( __DIR__, 3 ) . '/vendor';

		foreach( $candidates as $candidate )
		{
			if( $this->_fs->fileExists( $candidate . '/composer/installed.json' ) )
			{
				$real = $this->_fs->realpath( $candidate );

				return $real !== false ? $real : $candidate;
			}
		}

		return null;
	}

	/**
	 * @param string $vendorDir
	 * @return list<array>
	 */
	private function installedPackages( string $vendorDir ): array
	{
		$installed = $this->readJson( $vendorDir . '/composer/installed.json' );

		if( $installed === null )
		{
			return [];
		}

		// Composer 2 wraps the list in `packages`; Composer 1 is the bare list.
		$packages = $installed['packages'] ?? $installed;

		return is_array( $packages ) ? array_values( array_filter( $packages, 'is_array' ) ) : [];
	}

	/**
	 * Provider classes a package's composer data declares (string or list).
	 *
	 * @param array $package
	 * @return list<string>
	 */
	private function providersIn( array $package ): array
	{
		$declared = $package['extra']['neuron'][ self::EXTRA_KEY ] ?? null;

		if( is_string( $declared ) && $declared !== '' )
		{
			return [ $declared ];
		}

		if( is_array( $declared ) )
		{
			return array_values( array_filter( $declared, fn( $c ) => is_string( $c ) && $c !== '' ) );
		}

		return [];
	}

	/**
	 * @param string $path
	 * @return array|null
	 */
	private function readJson( string $path ): ?array
	{
		if( !$this->_fs->fileExists( $path ) )
		{
			return null;
		}

		$content = $this->_fs->readFile( $path );

		if( $content === false )
		{
			return null;
		}

		$data = json_decode( $content, true );

		return is_array( $data ) ? $data : null;
	}
}
