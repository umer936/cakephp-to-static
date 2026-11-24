<?php
declare(strict_types=1);

namespace CakephpToStatic\Test;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use CakephpToStatic\Command\AnalyzeCommand;
use CakephpToStatic\Plugin;
use PHPUnit\Framework\TestCase;

final class AnalyzeCommandTest extends TestCase
{
    private string $root;
    private StubConsoleOutput $out;
    private StubConsoleOutput $err;
    private ConsoleIo $io;

    protected function setUp(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', dirname(__DIR__));
        }
        $this->root = sys_get_temp_dir() . '/cakephp-to-static-command-' . bin2hex(random_bytes(8));
        foreach (['src', 'templates', 'config'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700, true);
        }
        file_put_contents($this->root . '/src/Example.php', '<?php $request->getSession();');
        $this->out = new StubConsoleOutput();
        $this->err = new StubConsoleOutput();
        $this->io = new ConsoleIo($this->out, $this->err);
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/src/Example.php');
        foreach (['src', 'templates', 'config'] as $directory) {
            rmdir($this->root . '/' . $directory);
        }
        rmdir($this->root);
    }

    public function testTextCommandReportsFindingsWithoutFailingByDefault(): void
    {
        $code = (new AnalyzeCommand())->run(['--root', $this->root], $this->io);

        self::assertSame(0, $code);
        self::assertStringContainsString('Static readiness: needs-review', $this->out->output());
        self::assertStringContainsString('src/Example.php:1 request.context', $this->out->output());
        self::assertSame('', $this->err->output());
    }

    public function testJsonCommandAndCiExitCode(): void
    {
        $code = (new AnalyzeCommand())->run(['--root', $this->root, '--format', 'json', '--fail-on-review'], $this->io);
        $report = json_decode($this->out->output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $code);
        self::assertSame('needs-review', $report['status']);
        self::assertSame('request.context', $report['findings'][0]['rule']);
    }

    public function testInvalidDirectoryFailsWithErrorOutput(): void
    {
        $code = (new AnalyzeCommand())->run(['--root', $this->root . '/missing'], $this->io);

        self::assertSame(1, $code);
        self::assertSame('', $this->out->output());
        self::assertStringContainsString('Analysis failed:', $this->err->output());
    }

    public function testPluginEntryPointAndCommandName(): void
    {
        $plugin = new Plugin(['name' => 'CakephpToStatic']);

        self::assertSame('CakephpToStatic', $plugin->getName());
        self::assertSame('to_static analyze', AnalyzeCommand::defaultName());
    }
}
