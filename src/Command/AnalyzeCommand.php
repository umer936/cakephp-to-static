<?php
declare(strict_types=1);

namespace CakephpToStatic\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Console\ConsoleOutput;
use CakephpToStatic\Analysis\Analyzer;
use Throwable;

final class AnalyzeCommand extends Command
{
    public static function defaultName(): string
    {
        return 'to_static analyze';
    }

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Inspect application PHP sources for static-deployment concerns. Does not export data or pages.')
            ->addOption('root', ['help' => 'Application directory to inspect.', 'default' => ROOT])
            ->addOption('format', ['help' => 'Report format.', 'default' => 'text', 'choices' => ['text', 'json']])
            ->addOption('fail-on-review', ['help' => 'Return a nonzero exit code when review findings exist.', 'boolean' => true]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        // Keep filenames and JSON intact instead of interpreting console markup.
        $io->setOutputAs(ConsoleOutput::RAW);
        try {
            $report = (new Analyzer())->analyze((string)$args->getOption('root'));
            if ($args->getOption('format') === 'json') {
                $io->out(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $io->out('Static readiness: ' . $report['status']);
                $io->out('PHP files scanned: ' . $report['files_scanned']);
                foreach ($report['findings'] as $finding) {
                    $io->out(sprintf('[%s] %s:%d %s: %s', $finding['severity'], $finding['file'], $finding['line'], $finding['rule'], $finding['message']));
                }
                foreach ($report['limitations'] as $limitation) {
                    $io->out('Note: ' . $limitation);
                }
            }
        } catch (Throwable $exception) {
            $io->err('Analysis failed: ' . $exception->getMessage());

            return static::CODE_ERROR;
        }

        return $args->getOption('fail-on-review') && $report['status'] === 'needs-review'
            ? static::CODE_ERROR : static::CODE_SUCCESS;
    }
}
