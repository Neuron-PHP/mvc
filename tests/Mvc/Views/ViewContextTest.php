<?php

namespace Mvc\Views;

use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;
use Neuron\Mvc\Views\ViewContext;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class ViewContextTest extends TestCase
{
	private Base $mockController;
	private Registry $mockRegistry;

	protected function setUp(): void
	{
		// Create a mock controller
		$this->mockController = $this->createMock( Base::class );

		// Create a test registry
		$this->mockRegistry = new Registry();
	}

	protected function tearDown(): void
	{
		// Clean up
		Registry::getInstance()->reset();
	}

	public function testConstructorAcceptsController()
	{
		$context = new ViewContext( $this->mockController );

		$this->assertInstanceOf( ViewContext::class, $context );
		$this->assertSame( $this->mockController, $context->getController() );
	}

	public function testConstructorAcceptsCustomRegistry()
	{
		$customRegistry = new Registry();
		$customRegistry->set( 'test', 'value' );

		$context = new ViewContext( $this->mockController, $customRegistry );

		$this->assertInstanceOf( ViewContext::class, $context );
	}

	public function testTitleSetsTitle()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$result = $context->title( 'Test Page' );

		$this->assertSame( $context, $result ); // Fluent interface
		$this->assertEquals( 'Test Page', $context->getTitle() );
	}

	public function testDescriptionSetsDescription()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$result = $context->description( 'Test Description' );

		$this->assertSame( $context, $result ); // Fluent interface
		$this->assertEquals( 'Test Description', $context->getDescription() );
	}

	public function testStatusSetsStatus()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$result = $context->status( HttpResponseStatus::CREATED );

		$this->assertSame( $context, $result ); // Fluent interface
		$this->assertEquals( HttpResponseStatus::CREATED, $context->getStatus() );
	}

	public function testDefaultStatusIsOK()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );

		$this->assertEquals( HttpResponseStatus::OK, $context->getStatus() );
	}

	public function testCacheSetsEnabled()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$result = $context->cache( true );

		$this->assertSame( $context, $result ); // Fluent interface
	}

	public function testWithKeyValueAddsData()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$result = $context->with( 'posts', ['post1', 'post2'] );

		$this->assertSame( $context, $result ); // Fluent interface
		$data = $context->getData();
		$this->assertEquals( ['post1', 'post2'], $data['posts'] );
	}

	public function testWithArrayMergesData()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$result = $context->with([
			'posts' => ['post1'],
			'count' => 5
		]);

		$this->assertSame( $context, $result ); // Fluent interface
		$data = $context->getData();
		$this->assertEquals( ['post1'], $data['posts'] );
		$this->assertEquals( 5, $data['count'] );
	}

	public function testWithChaining()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$context
			->with( 'key1', 'value1' )
			->with( 'key2', 'value2' )
			->with(['key3' => 'value3']);

		$data = $context->getData();
		$this->assertEquals( 'value1', $data['key1'] );
		$this->assertEquals( 'value2', $data['key2'] );
		$this->assertEquals( 'value3', $data['key3'] );
	}

	public function testWithCurrentUserReturnsFluentInterface()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$result = $context->withCurrentUser();

		$this->assertSame( $context, $result );
	}

	public function testWithCsrfTokenReturnsFluentInterface()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );
		$result = $context->withCsrfToken();

		$this->assertSame( $context, $result );
	}

	public function testFluentChaining()
	{
		$context = new ViewContext( $this->mockController, $this->mockRegistry );

		$result = $context
			->title( 'Test' )
			->description( 'Description' )
			->status( HttpResponseStatus::CREATED )
			->cache( true )
			->with( 'data', 'value' )
			->withCurrentUser()
			->withCsrfToken();

		$this->assertSame( $context, $result );
		$this->assertEquals( 'Test', $context->getTitle() );
		$this->assertEquals( 'Description', $context->getDescription() );
		$this->assertEquals( HttpResponseStatus::CREATED, $context->getStatus() );
	}

	public function testRenderHtmlCallsControllerRenderHtml()
	{
		$mockController = $this->createMock( Base::class );
		$mockController->expects( $this->once() )
			->method( 'renderHtml' )
			->with(
				$this->equalTo( HttpResponseStatus::OK ),
				$this->anything(),
				$this->equalTo( 'test' ),
				$this->equalTo( 'admin' ),
				$this->equalTo( null )
			)
			->willReturn( '<html>test</html>' );

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$result = $context->renderHtml( 'test', 'admin' );

		$this->assertEquals( '<html>test</html>', $result );
	}

	public function testRenderMarkdownCallsControllerRenderMarkdown()
	{
		$mockController = $this->createMock( Base::class );
		$mockController->expects( $this->once() )
			->method( 'renderMarkdown' )
			->with(
				$this->equalTo( HttpResponseStatus::OK ),
				$this->anything(),
				$this->equalTo( 'test' ),
				$this->equalTo( 'default' ),
				$this->equalTo( null )
			)
			->willReturn( '<html>markdown</html>' );

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$result = $context->renderMarkdown( 'test' );

		$this->assertEquals( '<html>markdown</html>', $result );
	}

	public function testRenderJsonCallsControllerRenderJson()
	{
		$mockController = $this->createMock( Base::class );
		$mockController->expects( $this->once() )
			->method( 'renderJson' )
			->with(
				$this->equalTo( HttpResponseStatus::OK ),
				$this->anything()
			)
			->willReturn( '{"test": "data"}' );

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$result = $context->renderJson();

		$this->assertEquals( '{"test": "data"}', $result );
	}

	public function testRenderXmlCallsControllerRenderXml()
	{
		$mockController = $this->createMock( Base::class );
		$mockController->expects( $this->once() )
			->method( 'renderXml' )
			->with(
				$this->equalTo( HttpResponseStatus::OK ),
				$this->anything()
			)
			->willReturn( '<xml>data</xml>' );

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$result = $context->renderXml();

		$this->assertEquals( '<xml>data</xml>', $result );
	}

	public function testRenderIsAliasForRenderHtml()
	{
		$mockController = $this->createMock( Base::class );
		$mockController->expects( $this->once() )
			->method( 'renderHtml' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->equalTo( 'index' ),
				$this->equalTo( 'default' ),
				$this->anything()
			)
			->willReturn( '<html>test</html>' );

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$result = $context->render();

		$this->assertEquals( '<html>test</html>', $result );
	}

	public function testTitleConcatenatesWithSiteName()
	{
		// Create a controller that has getName method
		$mockController = $this->getMockBuilder( Base::class )
			->onlyMethods(['renderHtml'])
			->addMethods(['getName'])
			->getMock();

		$mockController->method( 'getName' )
			->willReturn( 'My Site' );

		// Capture the data passed to renderHtml
		$capturedData = null;
		$mockController->method( 'renderHtml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<html></html>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context->title( 'Dashboard' )->renderHtml();

		$this->assertEquals( 'Dashboard | My Site', $capturedData['Title'] );
	}

	public function testTitleWithoutSiteName()
	{
		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderHtml
		$capturedData = null;
		$mockController->method( 'renderHtml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<html></html>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context->title( 'Dashboard' )->renderHtml();

		// When getName() doesn't exist, just the title is used
		$this->assertEquals( 'Dashboard', $capturedData['Title'] );
	}

	public function testDescriptionIsAddedToViewData()
	{
		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderHtml
		$capturedData = null;
		$mockController->method( 'renderHtml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<html></html>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context->description( 'Test Description' )->renderHtml();

		$this->assertEquals( 'Test Description', $capturedData['Description'] );
	}

	public function testWithCurrentUserInjectsUserFromRegistry()
	{
		$mockUser = (object)['id' => 123, 'name' => 'Test User'];
		$this->mockRegistry->set( 'Auth.User', $mockUser );

		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderHtml
		$capturedData = null;
		$mockController->method( 'renderHtml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<html></html>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context->withCurrentUser()->renderHtml();

		$this->assertSame( $mockUser, $capturedData['User'] );
	}

	public function testWithCurrentUserDoesNotInjectIfUserNotInRegistry()
	{
		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderHtml
		$capturedData = null;
		$mockController->method( 'renderHtml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<html></html>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context->withCurrentUser()->renderHtml();

		$this->assertArrayNotHasKey( 'User', $capturedData );
	}

	public function testWithCsrfTokenInjectsTokenFromRegistry()
	{
		$this->mockRegistry->set( 'Auth.CsrfToken', 'test-token-123' );

		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderHtml
		$capturedData = null;
		$mockController->method( 'renderHtml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<html></html>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context->withCsrfToken()->renderHtml();

		$this->assertEquals( 'test-token-123', $capturedData['CsrfToken'] );
	}

	public function testWithCsrfTokenDoesNotInjectIfTokenNotInRegistry()
	{
		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderHtml
		$capturedData = null;
		$mockController->method( 'renderHtml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<html></html>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context->withCsrfToken()->renderHtml();

		$this->assertArrayNotHasKey( 'CsrfToken', $capturedData );
	}

	public function testCustomDataIsMergedWithAutoInjectedData()
	{
		$mockUser = (object)['id' => 123];
		$this->mockRegistry->set( 'Auth.User', $mockUser );
		$this->mockRegistry->set( 'Auth.CsrfToken', 'token-123' );

		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderHtml
		$capturedData = null;
		$mockController->method( 'renderHtml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<html></html>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context
			->title( 'Page' )
			->description( 'Desc' )
			->with( 'posts', ['p1', 'p2'] )
			->withCurrentUser()
			->withCsrfToken()
			->renderHtml();

		$this->assertEquals( 'Page', $capturedData['Title'] );
		$this->assertEquals( 'Desc', $capturedData['Description'] );
		$this->assertEquals( ['p1', 'p2'], $capturedData['posts'] );
		$this->assertSame( $mockUser, $capturedData['User'] );
		$this->assertEquals( 'token-123', $capturedData['CsrfToken'] );
	}

	public function testJsonRenderingOmitsMetadata()
	{
		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderJson
		$capturedData = null;
		$mockController->method( 'renderJson' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '{}';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context
			->title( 'Page' )
			->description( 'Desc' )
			->with( 'data', 'value' )
			->renderJson();

		// Title and Description should NOT be in JSON output
		$this->assertArrayNotHasKey( 'Title', $capturedData );
		$this->assertArrayNotHasKey( 'Description', $capturedData );
		// But custom data should be
		$this->assertEquals( 'value', $capturedData['data'] );
	}

	public function testXmlRenderingOmitsMetadata()
	{
		$mockController = $this->createMock( Base::class );

		// Capture the data passed to renderXml
		$capturedData = null;
		$mockController->method( 'renderXml' )
			->willReturnCallback( function( $status, $data ) use ( &$capturedData ) {
				$capturedData = $data;
				return '<xml/>';
			});

		$context = new ViewContext( $mockController, $this->mockRegistry );
		$context
			->title( 'Page' )
			->description( 'Desc' )
			->with( 'data', 'value' )
			->renderXml();

		// Title and Description should NOT be in XML output
		$this->assertArrayNotHasKey( 'Title', $capturedData );
		$this->assertArrayNotHasKey( 'Description', $capturedData );
		// But custom data should be
		$this->assertEquals( 'value', $capturedData['data'] );
	}
}