<?php
/**
 * Creates a Markdown based view.
 */
namespace Neuron\Mvc\Views;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\MarkdownConverter;
use Neuron\Core\Exceptions\NotFound;
use Neuron\Core\NString;
use Neuron\Patterns\Registry;

/**
 * Generate html output by combining
 * a layout with a view and data.
 */
class Markdown extends Base implements IView
{
	use CacheableView;
	/**
	 * @param array $data
	 * @return string markdown output
	 * @throws NotFound
	 * @throws CommonMarkException
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

		$controllerName = new NString( $this->getController() )->toSnakeCase();
		$controllerPath = "$path/$controllerName";
		$view = $this->findMarkdownFile( $controllerPath, $this->getPage() );

		if( !$view )
		{
			throw new NotFound( "View notfound: {$this->getPage()}.md in $controllerPath" );
		}

		extract( $data );

		$layout = "$path/layouts/{$this->getLayout()}.php";

		if( !file_exists( $layout ) )
		{
			throw new NotFound( "View notfound: $layout" );
		}

		$content = $this->getCommonmarkConverter()->convert( file_get_contents( $view ) );

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

	/**
	 * Find markdown file in controller directory or nested subdirectories
	 *
	 * @param string $basePath
	 * @param string $pageName
	 * @return string|null
	 */
	protected function findMarkdownFile( string $basePath, string $pageName ): ?string
	{
		if( !is_dir( $basePath ) )
		{
			return null;
		}

		// First check direct path
		$directPath = "$basePath/$pageName.md";
		if( file_exists( $directPath ) )
		{
			return $directPath;
		}

		// Search recursively in subdirectories
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $basePath, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach( $iterator as $file )
		{
			if( $file->isFile() && $file->getExtension() === 'md' )
			{
				$fileName = $file->getBasename( '.md' );
				if( $fileName === $pageName )
				{
					return $file->getPathname();
				}
			}
		}

		return null;
	}

	/**
	 * @return MarkdownConverter
	 */
	protected function getCommonmarkConverter(): MarkdownConverter
	{
		$config = [
			'footnote' => [
				'backref_class'      => 'footnote-backref',
				'backref_symbol'     => '↩',
				'container_add_hr'   => true,
				'container_class'    => 'footnotes',
				'ref_class'          => 'footnote-ref',
				'ref_id_prefix'      => 'fnref:',
				'footnote_class'     => 'footnote',
				'footnote_id_prefix' => 'fn:',
			],
		];

		// Configure the Environment with all the CommonMark parsers/renderers
		$environment = new Environment( $config );
		$environment->addExtension( new CommonMarkCoreExtension() );

		// Add the extension
		$environment->addExtension( new FootnoteExtension() );

		return new MarkdownConverter( $environment );
	}

}
