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
use Neuron\Patterns\Registry;

/**
 * Generate html output by combining
 * a layout with a view and data.
 */
class Markdown extends Base implements IView
{
	/**
	 * @param array $Data
	 * @return string markdown output
	 * @throws NotFoundException
	 * @throws CommonMarkException
	 *
	 * Outputs the html data from the layout and view.
	 */
	public function render( array $Data ): string
	{
		$Path = Registry::getInstance()
							 ->get( "Views.Path" );

		if( !$Path )
		{
			$BasePath = Registry::getInstance()->get( "Base.Path" );
			$Path = "$BasePath/resources/views";
		}

		$View = "$Path/{$this->getController()}/{$this->getPage()}.md";

		if( !file_exists( $View ) )
		{
			throw new NotFoundException( "View notfound: $View" );
		}

		extract( $Data );

		$Layout = "$Path/layouts/{$this->getLayout()}.php";

		if( !file_exists( $Layout ) )
		{
			throw new NotFoundException( "View notfound: $Layout" );
		}

		$Content = $this->getCommonmarkConverter()->convert( file_get_contents( $View ) );

		ob_start();
		require( $Layout );
		$Page = ob_get_contents();
		ob_end_clean();

		return $Page;
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
