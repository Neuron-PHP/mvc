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
	 * @param array $Data
	 * @return string markdown output
	 * @throws NotFound
	 * @throws CommonMarkException
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
		$ControllerPath = "$Path/$ControllerName";
		$View = $this->findMarkdownFile( $ControllerPath, $this->getPage() );

		if( !$View )
		{
			throw new NotFound( "View notfound: {$this->getPage()}.md in $ControllerPath" );
		}

		extract( $Data );

		$Layout = "$Path/layouts/{$this->getLayout()}.php";

		if( !file_exists( $Layout ) )
		{
			throw new NotFound( "View notfound: $Layout" );
		}

		$Content = $this->getCommonmarkConverter()->convert( file_get_contents( $View ) );

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

	/**
	 * Find markdown file in controller directory or nested subdirectories
	 *
	 * @param string $BasePath
	 * @param string $PageName
	 * @return string|null
	 */
	protected function findMarkdownFile( string $BasePath, string $PageName ): ?string
	{
		if( !is_dir( $BasePath ) )
		{
			return null;
		}

		// First check direct path
		$DirectPath = "$BasePath/$PageName.md";
		if( file_exists( $DirectPath ) )
		{
			return $DirectPath;
		}

		// Search recursively in subdirectories
		$Iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $BasePath, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach( $Iterator as $File )
		{
			if( $File->isFile() && $File->getExtension() === 'md' )
			{
				$FileName = $File->getBasename( '.md' );
				if( $FileName === $PageName )
				{
					return $File->getPathname();
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
