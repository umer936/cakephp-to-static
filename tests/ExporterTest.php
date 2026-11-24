<?php
declare(strict_types=1);

namespace CakephpToStatic\Test;

use Cake\Http\Response;
use CakephpToStatic\Export\Exporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ExporterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/to-static-export-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/webroot/css', 0700, true);
        file_put_contents($this->root . '/webroot/css/site.css', 'body { color: black; }');
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testRendersPagesCopiesApprovedAssetsAndPreservesBrowserScripts(): void
    {
        $html = '<html><head><link href="/css/site.css" rel="stylesheet"></head><body><a href="/team">Team</a><script src="https://cdn.example.com/datatables.js"></script></body></html>';
        $config = ['pages' => ['/', '/team'], 'assets' => ['css']];
        $files = $this->export($config, $this->html($html));

        self::assertCount(3, $files);
        self::assertSame($html, file_get_contents($this->root . '/dist/team/index.html'));
        self::assertFileExists($this->root . '/dist/css/site.css');
    }

    public function testJsonNeverIncludesFieldsOutsideExplicitProjection(): void
    {
        $config = ['pages' => ['/'], 'assets' => [], 'datasets' => [
            ['table' => 'Team', 'fields' => ['first_name', 'country'], 'path' => 'data/team.json'],
        ]];
        (new Exporter())->export($config, $this->root . '/webroot', $this->root . '/dist',
            fn () => $this->html('<a href="/data/team.json">Data</a>'),
            static fn () => [['first_name' => 'Public', 'country' => 'US', 'comments' => 'PRIVATE', 'head_count' => 999, 'email' => 'private@example.test']],
        );

        self::assertSame([['first_name' => 'Public', 'country' => 'US']], json_decode(file_get_contents($this->root . '/dist/data/team.json'), true));
        self::assertStringNotContainsString('PRIVATE', file_get_contents($this->root . '/dist/data/team.json'));
    }

    #[DataProvider('unsafeResponses')]
    public function testRejectsUnsafeResponses(int $status, array $headers): void
    {
        $this->expectException(RuntimeException::class);
        try {
            $response = new Response(['status' => $status, 'body' => '<html></html>', 'charset' => 'UTF-8']);
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            $this->export(['pages' => ['/'], 'assets' => []], $response);
        } finally {
            self::assertDirectoryDoesNotExist($this->root . '/dist');
        }
    }

    public static function unsafeResponses(): iterable
    {
        yield 'redirect' => [302, ['Content-Type' => 'text/html', 'Location' => '/login']];
        yield 'error' => [500, ['Content-Type' => 'text/html']];
        yield 'cookie' => [200, ['Content-Type' => 'text/html', 'Set-Cookie' => 'session=secret']];
        yield 'private' => [200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']];
        yield 'no-store' => [200, ['Content-Type' => 'text/html', 'Cache-Control' => 'no-store']];
        yield 'vary-cookie' => [200, ['Content-Type' => 'text/html', 'Vary' => 'Cookie']];
        yield 'vary-all' => [200, ['Content-Type' => 'text/html', 'Vary' => '*']];
        yield 'not-html' => [200, ['Content-Type' => 'application/json']];
    }

    #[DataProvider('unsafeConfigurations')]
    public function testRejectsUnsafePathsAndCollisions(array $config): void
    {
        $this->expectException(RuntimeException::class);
        try {
            $this->export($config, $this->html('<html></html>'));
        } finally {
            self::assertDirectoryDoesNotExist($this->root . '/dist');
        }
    }

    public static function unsafeConfigurations(): iterable
    {
        yield 'asset traversal' => [['pages' => ['/'], 'assets' => ['../composer.json']]];
        yield 'hidden' => [['pages' => ['/'], 'assets' => ['.env']]];
        yield 'page traversal' => [['pages' => ['/../secret'], 'assets' => []]];
        yield 'encoded traversal' => [['pages' => ['/%2e%2e/secret'], 'assets' => []]];
        yield 'remote page' => [['pages' => ['//example.com'], 'assets' => []]];
        yield 'query page' => [['pages' => ['/?secret=yes'], 'assets' => []]];
        yield 'duplicate page' => [['pages' => ['/', '/'], 'assets' => []]];
        yield 'duplicate directory page' => [['pages' => ['/team', '/team/'], 'assets' => []]];
    }

    public function testRefusesSourceFilesAndSqlDumpsEvenWhenExplicitlyListed(): void
    {
        file_put_contents($this->root . '/webroot/data.sql', 'PRIVATE');
        $this->expectException(RuntimeException::class);
        $this->export(['pages' => ['/'], 'assets' => ['data.sql']], $this->html('<html></html>'));
    }

    public function testRefusesWildcardDatasetFields(): void
    {
        $this->expectException(RuntimeException::class);
        Exporter::validateDataset(['table' => 'Team', 'fields' => ['*'], 'path' => 'team.json']);
    }

    public function testRefusesExistingOutputWithoutTouchingIt(): void
    {
        mkdir($this->root . '/dist');
        file_put_contents($this->root . '/dist/keep', 'keep');
        $this->expectException(RuntimeException::class);
        try {
            $this->export(['pages' => ['/'], 'assets' => []], $this->html('<html></html>'));
        } finally {
            self::assertSame('keep', file_get_contents($this->root . '/dist/keep'));
        }
    }

    #[DataProvider('unsafeHtml')]
    public function testRejectsBrokenLinksAndBuildOriginLeaks(string $html): void
    {
        $this->expectException(RuntimeException::class);
        $this->export(['pages' => ['/'], 'assets' => [], 'source_origin' => 'http://build.example:8765'], $this->html($html));
    }

    public static function unsafeHtml(): iterable
    {
        yield ['<a href="/missing">Missing</a>'];
        yield ['<img src="http://127.0.0.1:8765/image.png">'];
        yield ['<a href="http://build.example:8765/">Build host</a>'];
        yield ['<a href="/?page=2">Dynamic query</a>'];
        yield ['<base href="/unexpected/"><p>Base</p>'];
    }

    private function export(array $config, Response $response): array
    {
        return (new Exporter())->export($config, $this->root . '/webroot', $this->root . '/dist', static fn () => $response);
    }

    private function html(string $body): Response
    {
        return new Response(['type' => 'html', 'body' => $body, 'charset' => 'UTF-8']);
    }
}
