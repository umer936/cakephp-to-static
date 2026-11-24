<?php
declare(strict_types=1);

namespace CakephpToStatic\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Cake\Http\Client;
use CakephpToStatic\Export\Exporter;
use RuntimeException;
use Throwable;

final class ExportCommand extends Command
{
    public static function defaultName(): string
    {
        return 'to_static export';
    }

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->setDescription('Export explicitly approved public pages and assets from a running application.')
            ->addOption('config', ['default' => ROOT . '/config/to_static.json', 'help' => 'Reviewed JSON export configuration.'])
            ->addOption('base-url', ['default' => 'http://127.0.0.1:8765', 'help' => 'Origin of a running, non-debug build instance (no path or credentials).'])
            ->addOption('output', ['default' => ROOT . '/dist', 'help' => 'New output directory; existing directories are never overwritten.']);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            if (Configure::read('debug')) {
                throw new RuntimeException('Disable debug for the build application before exporting public content.');
            }
            $baseUrl = rtrim((string)$args->getOption('base-url'), '/');
            $parts = parse_url($baseUrl);
            if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
                || array_intersect(['path', 'user', 'pass', 'query', 'fragment'], array_keys($parts))) {
                throw new RuntimeException('Base URL must be an HTTP(S) origin without path, credentials, query, or fragment.');
            }
            $source = file_get_contents((string)$args->getOption('config'));
            if ($source === false) {
                throw new RuntimeException('Cannot read export configuration.');
            }
            $config = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
            $config['source_origin'] = $baseUrl;
            $files = (new Exporter())->export(
                $config,
                WWW_ROOT,
                (string)$args->getOption('output'),
                // A fresh client for every request prevents carrying cookies between pages.
                static fn (string $path) => (new Client(['redirect' => false, 'timeout' => 60]))->get($baseUrl . $path),
                static function (array $definition): iterable {
                    $table = FactoryLocator::get('Table')->get($definition['table']);
                    if (array_diff($definition['fields'], $table->getSchema()->columns())) {
                        throw new RuntimeException('Dataset contains unknown columns.');
                    }
                    return $table->find()->select($definition['fields'], true)->disableAutoFields()->enableHydration(false)->all();
                },
            );
            $io->out('Exported ' . count($files) . ' files to ' . $args->getOption('output'));
            $io->out('Review the artifact before publishing. HTML is public; JSON contains only the approved field projection.');

            return static::CODE_SUCCESS;
        } catch (Throwable $error) {
            $io->err('Export failed: ' . $error->getMessage());

            return static::CODE_ERROR;
        }
    }
}
