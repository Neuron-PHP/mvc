<?php
/**
 * Example: Dynamic Cache Control in MVC Views
 * 
 * This example demonstrates how to dynamically enable or disable
 * caching for individual page renders in controller methods.
 */

use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Routing\Router;

class BlogController extends Base
{
	/**
	 * Blog listing - can be cached
	 */
	public function index()
	{
		$Posts = $this->getRecentPosts();
		
		// Enable caching for the blog listing page
		return $this->renderHtml(
			HttpResponseStatus::OK,
			[ 'posts' => $Posts ],
			'index',
			'blog',
			true // Cache enabled
		);
	}
	
	/**
	 * User dashboard - should not be cached
	 */
	public function dashboard()
	{
		$User = $this->getCurrentUser();
		$PersonalizedData = $this->getUserDashboardData( $User );
		
		// Disable caching for user-specific content
		return $this->renderHtml(
			HttpResponseStatus::OK,
			[ 
				'user' => $User,
				'data' => $PersonalizedData
			],
			'dashboard',
			'default',
			false // Cache explicitly disabled
		);
	}
	
	/**
	 * Static about page - force cache even if globally disabled
	 */
	public function about()
	{
		$Content = $this->getStaticContent( 'about' );
		
		// Force caching for static content
		return $this->renderHtml(
			HttpResponseStatus::OK,
			[ 'content' => $Content ],
			'about',
			'static',
			true // Force cache even if globally disabled
		);
	}
	
	/**
	 * Search results - let global config decide
	 */
	public function search()
	{
		$Query = $_GET['q'] ?? '';
		$Results = $this->searchPosts( $Query );
		
		// Use default cache behavior (null = use global config)
		return $this->renderHtml(
			HttpResponseStatus::OK,
			[ 
				'query' => $Query,
				'results' => $Results
			],
			'search',
			'default'
			// No cache parameter = uses global configuration
		);
	}
	
	/**
	 * Markdown documentation - cached
	 */
	public function docs()
	{
		$Page = $_GET['page'] ?? 'index';
		
		// Enable caching for documentation pages
		return $this->renderMarkdown(
			HttpResponseStatus::OK,
			[ 'page' => $Page ],
			$Page,
			'docs',
			true // Cache enabled for docs
		);
	}
	
	// Mock methods for example
	private function getRecentPosts() { return []; }
	private function getCurrentUser() { return null; }
	private function getUserDashboardData( $User ) { return []; }
	private function getStaticContent( $Page ) { return ''; }
	private function searchPosts( $Query ) { return []; }
}

/**
 * Usage Summary:
 * 
 * 1. Pass `true` as the 5th parameter to force caching
 * 2. Pass `false` to disable caching for that specific render
 * 3. Pass `null` or omit the parameter to use global cache configuration
 * 
 * This allows fine-grained control over caching on a per-page basis,
 * useful for mixing static and dynamic content in the same application.
 */