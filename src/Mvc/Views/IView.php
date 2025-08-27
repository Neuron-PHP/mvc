<?php

namespace Neuron\Mvc\Views;

/**
 * View interface for the Neuron MVC framework's rendering system.
 * 
 * This interface defines the contract that all view implementations must follow
 * in the MVC framework. Views are responsible for rendering data into various
 * output formats including HTML, JSON, XML, Markdown, and other presentation
 * formats. The interface ensures consistent rendering behavior across all view types.
 * 
 * Key responsibilities:
 * - Transform data arrays into formatted output strings
 * - Support multiple output formats through different implementations
 * - Integrate with the MVC controller layer for response generation
 * - Enable flexible view switching based on content negotiation
 * - Support caching mechanisms for performance optimization
 * 
 * Common view implementations:
 * - Html: Renders PHP templates with layout support
 * - Json: Generates JSON-formatted responses for APIs
 * - Xml: Creates XML output with proper structure
 * - Markdown: Processes Markdown content with layout integration
 * 
 * The render method should be idempotent and thread-safe, allowing
 * the same view instance to be reused for multiple rendering operations.
 * 
 * @package Neuron\Mvc\Views
 * @author Neuron-PHP Framework
 * @version 3.0.0
 * @since 1.0.0
 * 
 * @example
 * ```php
 * // Example view implementation
 * class CustomView implements IView
 * {
 *     public function render(array $data): string
 *     {
 *         $output = "<custom>";
 *         foreach ($data as $key => $value) {
 *             $output .= "<{$key}>" . htmlspecialchars($value) . "</{$key}>";
 *         }
 *         $output .= "</custom>";
 *         return $output;
 *     }
 * }
 * 
 * // Usage in controller
 * $view = new Html();
 * $content = $view->render([
 *     'title' => 'Welcome',
 *     'user' => $currentUser,
 *     'posts' => $recentPosts
 * ]);
 * ```
 */
interface IView
{
	/**
	 * Render data array into formatted output string.
	 * 
	 * @param array $Data Associative array of data to render
	 * @return string Formatted output ready for response
	 */
	public function render( array $Data ) : string;
}
