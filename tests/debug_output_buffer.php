#!/usr/bin/env php
<?php
/**
 * Debug script to identify output buffer issues in MVC tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Neuron\Data\Settings\Source\Yaml;
use Neuron\Mvc\Application;
use Neuron\Patterns\Registry;

echo "Initial output buffer level: " . ob_get_level() . "\n";

// Reset Registry singleton state
$registry = Registry::getInstance();
$registry->reset();

$Ini = new Yaml( './examples/config/neuron.yaml' );
$app = new Application( "1.0.0", $Ini );

echo "After creating app: " . ob_get_level() . "\n";

$app->setCaptureOutput( true );

echo "After setCaptureOutput: " . ob_get_level() . "\n";

$app->run( [
	"type"  => "POST",
	"route" => "/test"
] );

echo "After run: " . ob_get_level() . "\n";

$output = $app->getOutput();
echo "Output length: " . strlen( $output ) . "\n";

// Check open buffers
$levels = ob_get_level();
echo "\nOpen output buffers: $levels\n";
for( $i = 0; $i < $levels; $i++ )
{
	$status = ob_get_status( true );
	if( isset( $status[$i] ) )
	{
		echo "Buffer $i: " . json_encode( $status[$i] ) . "\n";
	}
}

// Clean up
while( ob_get_level() > 0 )
{
	ob_end_clean();
}

echo "After cleanup: " . ob_get_level() . "\n";