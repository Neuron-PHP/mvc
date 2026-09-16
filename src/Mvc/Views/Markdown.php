<?php
/**
 * Creates a Markdown based view.
 */
namespace Neuron\Mvc\Views;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Neuron\Core\Exceptions\NotFound;
use Neuron\Core\NString;

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

		$controllerName = new NString( $this->getController() )->toSnakeCase();
		$locator = new ViewLocator( $this->fs );
		$viewRelative = $controllerName . '/' . ltrim( str_replace( '\\', '/', $this->getPage() ), '/' ) . '.md';
		$view = $locator->locate( $viewRelative );

		if( !$view )
		{
			throw new NotFound( "View notfound: {$this->getPage()}.md" );
		}

		extract( $data );

		$layoutRelative = "layouts/{$this->getLayout()}.php";
		$layout = $locator->locate( $layoutRelative );

		if( !$layout )
		{
			throw new NotFound( "View notfound: $layoutRelative" );
		}

		$markdownContent = $this->fs->readFile( $view );

		if( $markdownContent === false )
		{
			throw new NotFound( "Failed to read markdown file: $view" );
		}

		$content = $this->getCommonmarkConverter()->convert( $markdownContent );

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
	 * Find markdown file using directory path
	 *
	 * @param string $basePath Base directory for controller views
	 * @param string $pageName Relative path to markdown file (e.g., "cms/guides/authentication")
	 * @return string|null Full path to markdown file or null if not found
	 */
	protected function findMarkdownFile( string $basePath, string $pageName ): ?string
	{
		if( !$this->fs->isDir( $basePath ) )
		{
			return null;
		}

		// Normalize path separators to forward slashes
		$pageName = str_replace( '\\', '/', $pageName );

		// Security: prevent directory traversal attacks
		if( str_contains( $pageName, '..' ) )
		{
			return null;
		}

		// Build full path
		$fullPath = "$basePath/$pageName.md";

		// Return path if file exists
		if( $this->fs->fileExists( $fullPath ) )
		{
			return $fullPath;
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

		// Add GitHub Flavored Markdown features (tables, autolinks, strikethrough, task lists)
		$environment->addExtension( new GithubFlavoredMarkdownExtension() );

		// Add footnote support
		$environment->addExtension( new FootnoteExtension() );

		return new MarkdownConverter( $environment );
	}

}
