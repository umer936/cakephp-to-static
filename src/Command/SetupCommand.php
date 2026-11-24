<?php
declare(strict_types=1);

namespace CakephpToStatic\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\FactoryLocator;
use CakephpToStatic\Export\Exporter;
use RuntimeException;
use Throwable;

final class SetupCommand extends Command
{
    public static function defaultName(): string
    {
        return 'to_static setup';
    }

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->setDescription('Wizard: explicitly approve public pages, assets, and optional JSON fields.')
            ->addOption('config', ['default' => ROOT . '/config/to_static.json', 'help' => 'New JSON configuration to create.']);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $path = (string)$args->getOption('config');
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('Configuration already exists; edit the reviewed file rather than overwriting it.');
            }
            $io->out('Prefer rendered HTML. A JSON export is optional and is public to everyone.');
            $io->out('Only include pages accessible anonymously. Remove private fields in your queries/templates too.');
            $split = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== ''));
            $config = [
                'pages' => $split($io->ask('Public page paths, comma separated', '/')),
                'assets' => $split($io->ask('Approved webroot files/directories (no automatic whole-webroot copy)', 'css,img,favicon.ico')),
                'datasets' => [],
            ];
            while ($io->askChoice('Add an optional public JSON dataset?', ['y', 'n'], 'n') === 'y') {
                $alias = $io->ask('CakePHP model alias (for example Team)');
                $columns = FactoryLocator::get('Table')->get($alias)->getSchema()->columns();
                $io->out('Available column names: ' . implode(', ', $columns));
                $io->out('No fields are selected by default. Do not select secrets, comments, email, or internal counts.');
                $definition = [
                    'table' => $alias,
                    'fields' => $split($io->ask('Exact PUBLIC fields, comma separated')),
                    'path' => $io->ask('JSON output path', 'data/' . strtolower($alias) . '.json'),
                ];
                Exporter::validateDataset($definition);
                if (array_diff($definition['fields'], $columns)) {
                    throw new RuntimeException('Unknown column selected.');
                }
                $config['datasets'][] = $definition;
            }
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $io->out($json);
            $io->out('Approving a model publishes ALL rows from that model query. There is no automatic privacy or row-level authorization check.');
            if ($io->askChoice('Approve this as PUBLIC build configuration?', ['y', 'n'], 'n') !== 'y') {
                $io->out('Cancelled; nothing written.');

                return static::CODE_ERROR;
            }
            $handle = fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException('Cannot create configuration.');
            }
            try {
                if (fwrite($handle, $json) !== strlen($json)) {
                    throw new RuntimeException('Cannot write configuration.');
                }
            } finally {
                fclose($handle);
            }
            $io->out('Saved. Review and commit this configuration; CI can now run to_static export without prompts.');

            return static::CODE_SUCCESS;
        } catch (Throwable $error) {
            $io->err('Setup failed: ' . $error->getMessage());

            return static::CODE_ERROR;
        }
    }
}
