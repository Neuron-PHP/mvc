<?php

namespace Neuron\Mvc\Views;

use Neuron\Core\Registry\RegistryKeys;
use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;
use Neuron\Patterns\Registry;

/**
 * Resolve view files across an ordered list of roots.
 *
 * The first matching file wins, so a site copy overrides a package fallback.
 * RegistryKeys::VIEWS_PATH may be a string (legacy) or a list of directories.
 */
class ViewLocator
{
	private IFileSystem $_fs;

	public function __construct( ?IFileSystem $fs = null )
	{
		$this->_fs = $fs ?? new RealFileSystem();
	}

	/**
	 * Ordered view roots. Site path first when Application registered it that way.
	 *
	 * @return list<string>
	 */
	public function paths(): array
	{
		$registered = Registry::getInstance()->get( RegistryKeys::VIEWS_PATH );
		$paths = self::normalize( $registered );

		if( $paths === [] )
		{
			$basePath = Registry::getInstance()->get( RegistryKeys::BASE_PATH );

			if( is_string( $basePath ) && $basePath !== '' )
			{
				$paths[] = rtrim( $basePath, '/' ) . '/resources/views';
			}
		}

		return $paths;
	}

	/**
	 * First existing file for a path relative to a view root, or null.
	 */
	public function locate( string $relative, ?IFileSystem $fs = null ): ?string
	{
		$fs = $fs ?? $this->_fs;
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );

		if( $relative === '' || str_contains( $relative, '..' ) )
		{
			return null;
		}

		foreach( $this->paths() as $root )
		{
			$candidate = $root . '/' . $relative;

			if( $fs->fileExists( $candidate ) )
			{
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Append a fallback root if it is not already registered.
	 */
	public static function appendPath( string $path ): void
	{
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );

		if( $path === '' )
		{
			return;
		}

		$registry = Registry::getInstance();
		$paths = self::normalize( $registry->get( RegistryKeys::VIEWS_PATH ) );

		foreach( $paths as $existing )
		{
			if( $existing === $path )
			{
				return;
			}
		}

		$paths[] = $path;
		$registry->set(
			RegistryKeys::VIEWS_PATH,
			count( $paths ) === 1 ? $paths[0] : $paths
		);
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	public static function normalize( mixed $value ): array
	{
		$paths = [];

		if( is_string( $value ) && $value !== '' )
		{
			$paths[] = rtrim( str_replace( '\\', '/', $value ), '/' );
		}
		elseif( is_array( $value ) )
		{
			foreach( $value as $path )
			{
				if( !is_string( $path ) || $path === '' )
				{
					continue;
				}

				$paths[] = rtrim( str_replace( '\\', '/', $path ), '/' );
			}
		}

		return array_values( array_unique( $paths ) );
	}
}
