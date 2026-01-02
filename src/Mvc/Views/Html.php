<?php
/**
 * Creates an HTML based view.
 */
namespace Neuron\Mvc\Views;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Core\NString;
use Neuron\Log\Log;
use Neuron\Patterns\Registry;

/**
 * Generate html output by combining
 * a layout with a view and data.
 */
class Html extends Base implements IView
{
	use CacheableView;

	/**
	 * @param array $data
	 * @return string html output
	 * @throws NotFound
	 *
	 * Outputs the html data from the layout and view.
	 */
	public function render( array $data ): string
	{
		$cacheKey = $this->getCacheKey( $data );

		if( $cacheKey && $cachedContent = $this->getCachedContent( $cacheKey ) )
		{
			return $cachedContent;
		}

		$path = Registry::getInstance()
									->get( "Views.Path" );

		if( !$path )
		{
			$basePath = Registry::getInstance()->get( "Base.Path" );
			$path = "$basePath/resources/views";
		}

		// Convert controller name to snake_case, preserving directory separators
		$controllerParts = explode( '/', $this->getController() );
		$snakeCaseParts = array_map(
			fn( $part ) => ( new NString( $part ) )->toSnakeCase(),
			$controllerParts
		);
		$controllerName = implode( '/', $snakeCaseParts );

		// Debug logging
		Log::debug( "Controller: " . $this->getController() );
		Log::debug( "Page: " . $this->getPage() );
		Log::debug( "Controller Name: " . $controllerName );

		$view = "$path/$controllerName/{$this->getPage()}.php";

		if( !$this->fs->fileExists( $view ) )
		{
			throw new NotFound( "View notfound: $view" );
		}

		extract( $data );

		$layout = "$path/layouts/{$this->getLayout()}.php";

		if( !$this->fs->fileExists( $layout ) )
		{
			throw new NotFound( "View notfound: $layout" );
		}

		ob_start();
		require( $view );
		$content = ob_get_contents();
		ob_end_clean();

		ob_start();
		require( $layout );
		$page = ob_get_contents();
		ob_end_clean();

		if( $cacheKey )
		{
			$this->setCachedContent( $cacheKey, $page );
		}

		return $page;
	}
}
