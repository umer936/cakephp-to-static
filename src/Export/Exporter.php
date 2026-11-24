<?php
declare(strict_types=1);

namespace CakephpToStatic\Export;

use DOMDocument;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Psr\Http\Message\ResponseInterface;

/** Publishes only explicitly approved pages, assets, and projected JSON fields. */
final class Exporter
{
    private array $written = [];

    public function export(array $config, string $webroot, string $output, callable $fetch, ?callable $dataset = null): array
    {
        $this->written = [];
        $webroot = realpath($webroot);
        $parent = realpath(dirname($output));
        if ($webroot === false || $parent === false || !is_dir($webroot)
            || !preg_match('/^[a-zA-Z0-9_-]+$/D', basename($output)) || file_exists($output) || is_link($output)) {
            throw new RuntimeException('Use an existing webroot and a new output directory with an existing parent. Output is never overwritten.');
        }
        $output = $parent . DIRECTORY_SEPARATOR . basename($output);
        if (str_starts_with(strtolower(str_replace('\\', '/', $output)) . '/', strtolower(str_replace('\\', '/', $webroot)) . '/')) {
            throw new RuntimeException('Output must be outside webroot.');
        }
        if (empty($config['pages']) || !is_array($config['pages']) || !is_array($config['assets'] ?? null) || !is_array($config['datasets'] ?? [])) {
            throw new RuntimeException('Configuration requires pages, assets, and optional datasets arrays.');
        }
        if (!mkdir($output, 0755)) {
            throw new RuntimeException('Cannot create output directory.');
        }

        try {
            foreach ($config['assets'] as $asset) {
                $this->safePath($asset);
                $source = $webroot . '/' . $asset;
                $this->assertSource($source, $webroot);
                if (is_dir($source)) {
                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                        if ($file->isDir()) {
                            continue;
                        }
                        $this->copyAsset($file->getPathname(), $webroot, $output);
                    }
                } else {
                    $this->copyAsset($source, $webroot, $output);
                }
            }
            foreach ($config['datasets'] ?? [] as $definition) {
                self::validateDataset($definition);
                if ($dataset === null) {
                    throw new RuntimeException('A dataset reader is required.');
                }
                $rows = [];
                foreach ($dataset($definition) as $record) {
                    $row = [];
                    foreach ($definition['fields'] as $field) {
                        if (!array_key_exists($field, $record) || (!is_scalar($record[$field]) && $record[$field] !== null)) {
                            throw new RuntimeException('Dataset fields must exist and contain scalar or null values.');
                        }
                        $row[$field] = $record[$field];
                    }
                    $rows[] = $row;
                }
                $this->write($output, $definition['path'], json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            $pages = [];
            foreach ($config['pages'] as $path) {
                $target = $this->pageTarget($path);
                $response = $fetch($path);
                if (!$response instanceof ResponseInterface || $response->getStatusCode() !== 200
                    || !preg_match('~^text/html(?:;|$)~i', $response->getHeaderLine('Content-Type'))
                    || $response->hasHeader('Set-Cookie') || $response->hasHeader('Location')
                    || preg_match('/private|no-store/i', $response->getHeaderLine('Cache-Control'))
                    || preg_match('/(?:cookie|authorization|\*)/i', $response->getHeaderLine('Vary'))) {
                    throw new RuntimeException('Refusing non-public, redirected, non-HTML, or unsuccessful response: ' . $path);
                }
                $body = (string)$response->getBody();
                if (stripos($body, '<?') !== false) {
                    throw new RuntimeException('Unexpected server-side markup in response: ' . $path);
                }
                $this->write($output, $target, $body);
                $pages[$path] = $body;
            }
            foreach ($pages as $path => $html) {
                $this->validateLinks($path, $html, $config['source_origin'] ?? '');
            }
        } catch (\Throwable $error) {
            // Only remove files created by this invocation, never an existing directory.
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($output);
            throw $error;
        }

        return array_keys($this->written);
    }

    public static function validateDataset(array $definition): void
    {
        if (!is_string($definition['table'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_.]*$/D', $definition['table'])
            || empty($definition['fields']) || !is_array($definition['fields'])
            || count(array_unique($definition['fields'], SORT_REGULAR)) !== count($definition['fields'])
            || !is_string($definition['path'] ?? null) || !str_ends_with($definition['path'], '.json')) {
            throw new RuntimeException('Datasets require a model alias, explicit unique fields, and a .json output path.');
        }
        foreach ($definition['fields'] as $field) {
            if (!is_string($field) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field)) {
                throw new RuntimeException('Dataset fields must be literal column names, never wildcards or expressions.');
            }
        }
    }

    private function safePath(mixed $path): void
    {
        if (!is_string($path) || !preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_./-]*$~D', $path)) {
            throw new RuntimeException('Unsafe relative export path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment[0] === '.') {
                throw new RuntimeException('Hidden files, empty segments, and traversal are not allowed.');
            }
        }
    }

    private function pageTarget(mixed $path): string
    {
        if ($path === '/') {
            return 'index.html';
        }
        if (!is_string($path) || !str_starts_with($path, '/')) {
            throw new RuntimeException('Pages must be root-relative paths without query strings.');
        }
        $relative = trim($path, '/');
        $this->safePath($relative);
        if (str_contains($relative, '.') || str_starts_with($path, '//')) {
            throw new RuntimeException('Only extensionless page URLs are supported.');
        }

        return $relative . '/index.html';
    }

    private function assertSource(string $source, string $root): void
    {
        $real = realpath($source);
        if ($real === false || is_link($source) || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root) . '/')) {
            throw new RuntimeException('Asset is missing, symlinked, or outside webroot.');
        }
        $relative = substr(str_replace('\\', '/', $source), strlen(str_replace('\\', '/', $root)) + 1);
        $cursor = $root;
        foreach (explode('/', $relative) as $segment) {
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException('Symlinked assets are not supported.');
            }
        }
    }

    private function copyAsset(string $source, string $root, string $output): void
    {
        $this->assertSource($source, $root);
        $relative = str_replace('\\', '/', substr($source, strlen($root) + 1));
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'pdf', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm'], true)) {
            throw new RuntimeException('Unapproved asset type: ' . $relative);
        }
        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new RuntimeException('Cannot read asset.');
        }
        $this->write($output, $relative, $contents);
    }

    private function write(string $output, string $path, string $contents): void
    {
        $this->safePath($path);
        $key = strtolower($path);
        if (isset($this->written[$key])) {
            throw new RuntimeException('Output collision: ' . $path);
        }
        $target = $output . '/' . $path;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
            throw new RuntimeException('Cannot create export subdirectory.');
        }
        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write exported file.');
        }
        $this->written[$key] = true;
    }

    private function validateLinks(string $page, string $html, string $sourceOrigin): void
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document->getElementsByTagName('base')->length > 0) {
            throw new RuntimeException('HTML base elements are not supported.');
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (['href', 'src', 'poster', 'action'] as $attribute) {
                $url = trim($element->getAttribute($attribute));
                if ($url === '' || $url[0] === '#') {
                    continue;
                }
                if (str_starts_with($url, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
                    $host = parse_url(str_starts_with($url, '//') ? 'http:' . $url : $url, PHP_URL_HOST);
                    if ($host && ($host === parse_url($sourceOrigin, PHP_URL_HOST) || in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]'], true))) {
                        throw new RuntimeException('Build-server URL leaked into page: ' . $page);
                    }
                    continue;
                }
                if (str_contains($url, '?')) {
                    throw new RuntimeException('Query-dependent local link needs explicit handling: ' . $page);
                }
                $path = rawurldecode(explode('#', $url, 2)[0]);
                $segments = [];
                $base = $page === '/' ? '/' : rtrim($page, '/') . '/';
                foreach (explode('/', str_starts_with($path, '/') ? $path : $base . $path) as $segment) {
                    if ($segment === '..') {
                        array_pop($segments);
                    } elseif ($segment !== '' && $segment !== '.') {
                        $segments[] = $segment;
                    }
                }
                $path = implode('/', $segments);
                if (!isset($this->written[strtolower($path)]) && !isset($this->written[strtolower(($path === '' ? '' : $path . '/') . 'index.html')])) {
                    throw new RuntimeException('Unexported local link on ' . $page . ': ' . $url);
                }
            }
        }
    }
}
