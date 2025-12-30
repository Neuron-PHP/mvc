<?php

namespace Tests\Mvc\Cli\Commands\Cache;

use Neuron\Mvc\Cli\Commands\Cache\ClearCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Cli\IO\TestInputReader;
use PHPUnit\Framework\TestCase;

class ClearCommandTest extends TestCase
{
	private ClearCommand $command;
	private Output $output;
	private TestInputReader $inputReader;
	private string $tempDir;

	protected function setUp(): void
	{
		$this->command = new ClearCommand();
		$this->output = new Output(false); // No colors in tests
		$this->inputReader = new TestInputReader();

		$this->command->setOutput($this->output);
		$this->command->setInputReader($this->inputReader);

		// Create temp directory for tests
		$this->tempDir = sys_get_temp_dir() . '/neuron_mvc_test_' . uniqid();
		mkdir($this->tempDir);
	}

	protected function tearDown(): void
	{
		// Clean up temp directory
		if (is_dir($this->tempDir)) {
			$this->recursiveRemoveDirectory($this->tempDir);
		}
	}

	private function recursiveRemoveDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$path = $dir . '/' . $file;
			is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
		}
		rmdir($dir);
	}

	public function testGetName(): void
	{
		$this->assertEquals('cache:clear', $this->command->getName());
	}

	public function testGetDescription(): void
	{
		$this->assertEquals('Clear MVC view cache entries', $this->command->getDescription());
	}

	public function testConfigure(): void
	{
		$this->command->configure();

		$options = $this->command->getOptions();

		$this->assertArrayHasKey('type', $options);
		$this->assertArrayHasKey('expired', $options);
		$this->assertArrayHasKey('force', $options);
		$this->assertArrayHasKey('config', $options);
	}

	public function testExecuteWithMissingConfig(): void
	{
		$input = new Input([]);
		$this->command->setInput($input);

		$exitCode = $this->command->execute();

		$this->assertEquals(1, $exitCode);
	}

	public function testExecuteWithDisabledCache(): void
	{
		// Create config with disabled cache
		$config = "cache:\n  enabled: false\n  path: cache/views\n  ttl: 3600\n";
		file_put_contents($this->tempDir . '/neuron.yaml', $config);

		$input = new Input(['--config=' . $this->tempDir]);
		$this->command->setInput($input);

		$exitCode = $this->command->execute();

		// Should return 0 with warning
		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithConfirmation(): void
	{
		// User confirms clear
		$this->inputReader->addResponse('yes');

		// Create config with enabled cache
		$config = "cache:\n  enabled: true\n  path: {$this->tempDir}/cache\n  ttl: 3600\n";
		file_put_contents($this->tempDir . '/neuron.yaml', $config);
		mkdir($this->tempDir . '/cache');

		$input = new Input(['--config=' . $this->tempDir]);
		$this->command->setInput($input);

		$exitCode = $this->command->execute();

		$this->assertEquals(0, $exitCode);

		// Verify confirmation was asked
		$prompts = $this->inputReader->getPromptHistory();
		$this->assertCount(1, $prompts);
		$this->assertStringContainsString('Clear all cache entries?', $prompts[0]);
	}

	public function testExecuteCancelledByUser(): void
	{
		// User declines
		$this->inputReader->addResponse('no');

		// Create config with enabled cache
		$config = "cache:\n  enabled: true\n  path: {$this->tempDir}/cache\n  ttl: 3600\n";
		file_put_contents($this->tempDir . '/neuron.yaml', $config);
		mkdir($this->tempDir . '/cache');

		$input = new Input(['--config=' . $this->tempDir]);
		$this->command->setInput($input);

		$exitCode = $this->command->execute();

		// Should return 0 (cancelled, not error)
		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithForceFlag(): void
	{
		// No input reader response needed - force skips confirmation

		// Create config with enabled cache
		$config = "cache:\n  enabled: true\n  path: {$this->tempDir}/cache\n  ttl: 3600\n";
		file_put_contents($this->tempDir . '/neuron.yaml', $config);
		mkdir($this->tempDir . '/cache');

		$input = new Input(['--config=' . $this->tempDir, '--force']);
		$this->command->setInput($input);

		$exitCode = $this->command->execute();

		$this->assertEquals(0, $exitCode);

		// Verify no prompts were shown
		$prompts = $this->inputReader->getPromptHistory();
		$this->assertCount(0, $prompts);
	}

	public function testExecuteExpiredOnly(): void
	{
		$this->inputReader->addResponse('yes');

		// Create config with enabled cache
		$config = "cache:\n  enabled: true\n  path: {$this->tempDir}/cache\n  ttl: 3600\n";
		file_put_contents($this->tempDir . '/neuron.yaml', $config);
		mkdir($this->tempDir . '/cache');

		$input = new Input(['--config=' . $this->tempDir, '--expired']);
		$this->command->setInput($input);

		$exitCode = $this->command->execute();

		$this->assertEquals(0, $exitCode);

		// Verify correct confirmation message
		$prompts = $this->inputReader->getPromptHistory();
		$this->assertStringContainsString('expired', $prompts[0]);
	}

	public function testExecuteWithTypeFilter(): void
	{
		$this->inputReader->addResponse('yes');

		// Create config with enabled cache
		$config = "cache:\n  enabled: true\n  path: {$this->tempDir}/cache\n  ttl: 3600\n";
		file_put_contents($this->tempDir . '/neuron.yaml', $config);
		mkdir($this->tempDir . '/cache');

		$input = new Input(['--config=' . $this->tempDir, '--type=html']);
		$this->command->setInput($input);

		$exitCode = $this->command->execute();

		$this->assertEquals(0, $exitCode);

		// Verify correct confirmation message
		$prompts = $this->inputReader->getPromptHistory();
		$this->assertStringContainsString('html', $prompts[0]);
	}
}
