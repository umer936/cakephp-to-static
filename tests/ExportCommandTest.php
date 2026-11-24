<?php
declare(strict_types=1);

namespace CakephpToStatic\Test;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use CakephpToStatic\Command\ExportCommand;
use PHPUnit\Framework\TestCase;

final class ExportCommandTest extends TestCase
{
    public function testDebugBuildIsRejectedBeforeReadingConfiguration(): void
    {
        $previous = Configure::read('debug');
        Configure::write('debug', true);
        try {
            $error = new StubConsoleOutput();
            $code = (new ExportCommand())->run([], new ConsoleIo(new StubConsoleOutput(), $error));
            self::assertSame(1, $code);
            self::assertStringContainsString('Disable debug', $error->output());
        } finally {
            Configure::write('debug', $previous);
        }
    }

    public function testNonOriginBaseUrlIsRejected(): void
    {
        $previous = Configure::read('debug');
        Configure::write('debug', false);
        try {
            $error = new StubConsoleOutput();
            $code = (new ExportCommand())->run(['--base-url', 'https://example.test/private'], new ConsoleIo(new StubConsoleOutput(), $error));
            self::assertSame(1, $code);
            self::assertStringContainsString('Base URL must be', $error->output());
        } finally {
            Configure::write('debug', $previous);
        }
    }
}
