<?php
/**
 * Creates an HTML based view.
 */
namespace Neuron\Mvc\Views;

use Neuron\Core\Exceptions\NotFound;
use Neuron\Core\NString;
use Neuron\Patterns\Registry;

/**
 * Generate html output by combining
 * a layout with a view and data.
 */
class Html extends Base implements IView
{
	use CacheableView;

	/**
	 * @param array $Data
	 * @return string html output
	 * @throws NotFound
	 *
	 * Outputs the html data from the layout and view.
	 */
	public function render( array $Data ): string
	{
		$CacheKey = $this->getCacheKey( $Data );

		if( $CacheKey && $CachedContent = $this->getCachedContent( $CacheKey ) )
		{
			return $CachedContent;
		}

		$Path = Registry::getInstance()
									->get( "Views.Path" );

		if( !$Path )
		{
			$BasePath = Registry::getInstance()->get( "Base.Path" );
			$Path = "$BasePath/resources/views";
		}

		$ControllerName = new NString( $this->getController() )->toSnakeCase();
		$View = "$Path/$ControllerName/{$this->getPage()}.php";

		if( !file_exists( $View ) )
		{
			throw new NotFound( "View notfound: $View" );
		}

		extract( $Data );

		$Layout = "$Path/layouts/{$this->getLayout()}.php";

		if( !file_exists( $Layout ) )
		{
			throw new NotFound( "View notfound: $Layout" );
		}

		ob_start();
		require( $View );
		$Content = ob_get_contents();
		ob_end_clean();

		ob_start();
		require( $Layout );
		$Page = ob_get_contents();
		ob_end_clean();

		if( $CacheKey )
		{
			$this->setCachedContent( $CacheKey, $Page );
		}

		return $Page;
	}
}
