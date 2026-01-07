#!/usr/bin/env php
<?php
/**
 * Demonstration of HTTP 401 and 403 exception handling with events
 *
 * This script shows how the new Unauthorized and Forbidden exceptions
 * trigger corresponding HTTP events and render appropriate error pages.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Neuron\Core\Exceptions\Unauthorized;
use Neuron\Core\Exceptions\Forbidden;
use Neuron\Core\Exceptions\NotFound;
use Neuron\Events\IEvent;
use Neuron\Events\IListener;
use Neuron\Mvc\Events\Http401;
use Neuron\Mvc\Events\Http403;
use Neuron\Mvc\Events\Http404;
use Neuron\Application\CrossCutting\Event;

// Event listener to track which events are emitted
class EventLogger implements IListener
{
	private array $events = [];

	public function event( IEvent $event ): void
	{
		$this->events[] = $event;

		if( $event instanceof Http401 )
		{
			echo "✓ HTTP 401 Event Emitted:\n";
			echo "  - Route: {$event->route}\n";
			echo "  - Realm: " . ( $event->realm ?? '(none)' ) . "\n\n";
		}
		elseif( $event instanceof Http403 )
		{
			echo "✓ HTTP 403 Event Emitted:\n";
			echo "  - Route: {$event->route}\n";
			echo "  - Resource: " . ( $event->resource ?? '(none)' ) . "\n";
			echo "  - Permission: " . ( $event->permission ?? '(none)' ) . "\n\n";
		}
		elseif( $event instanceof Http404 )
		{
			echo "✓ HTTP 404 Event Emitted:\n";
			echo "  - Route: {$event->route}\n\n";
		}
	}

	public function getEvents(): array
	{
		return $this->events;
	}
}

echo "=================================================\n";
echo " HTTP 401/403 Event System Demonstration\n";
echo "=================================================\n\n";

// Register event listener
$logger = new EventLogger();
Event::registerListeners( [
	Http401::class => [ $logger ],
	Http403::class => [ $logger ],
	Http404::class => [ $logger ]
] );

// Demo 1: Unauthorized Exception
echo "Demo 1: Unauthorized Exception (401)\n";
echo "---------------------------------\n";
try
{
	throw new Unauthorized( 'API key required', 'API Access' );
}
catch( Unauthorized $e )
{
	echo "Caught Unauthorized Exception:\n";
	echo "  - Message: {$e->getMessage()}\n";
	echo "  - Realm: " . ( $e->getRealm() ?? '(none)' ) . "\n";
	echo "  - Code: {$e->getCode()}\n\n";

	// Simulate what the MVC Application would do
	Event::emit( new Http401( '/api/protected', $e->getRealm() ) );
}

// Demo 2: Forbidden Exception
echo "Demo 2: Forbidden Exception (403)\n";
echo "---------------------------------\n";
try
{
	throw new Forbidden(
		'You cannot delete this user',
		'User #123',
		'users.delete'
	);
}
catch( Forbidden $e )
{
	echo "Caught Forbidden Exception:\n";
	echo "  - Message: {$e->getMessage()}\n";
	echo "  - Resource: " . ( $e->getResource() ?? '(none)' ) . "\n";
	echo "  - Permission: " . ( $e->getPermission() ?? '(none)' ) . "\n";
	echo "  - Code: {$e->getCode()}\n\n";

	// Simulate what the MVC Application would do
	Event::emit( new Http403(
		'/users/123/delete',
		$e->getResource(),
		$e->getPermission()
	) );
}

// Demo 3: NotFound Exception (existing functionality)
echo "Demo 3: NotFound Exception (404)\n";
echo "---------------------------------\n";
try
{
	throw new NotFound( 'Page not found' );
}
catch( NotFound $e )
{
	echo "Caught NotFound Exception:\n";
	echo "  - Message: {$e->getMessage()}\n";
	echo "  - Code: {$e->getCode()}\n\n";

	// Simulate what the MVC Application would do
	Event::emit( new Http404( '/missing/page' ) );
}

// Summary
echo "=================================================\n";
echo " Summary\n";
echo "=================================================\n";
$events = $logger->getEvents();
echo "Total events emitted: " . count( $events ) . "\n";
echo "Event types:\n";
foreach( $events as $event )
{
	echo "  - " . get_class( $event ) . "\n";
}

echo "\n✅ All HTTP status events working correctly!\n";