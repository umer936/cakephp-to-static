<?php
declare(strict_types=1);

namespace CakephpToStatic\Analysis;

use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveCallbackFilterIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** Source-only heuristics: findings are review prompts, never proof of exportability. */
final class Analyzer
{
    /** @return array{status: string, files_scanned: int, findings: list<array{rule: string, severity: string, file: string, line: int, message: string}>, limitations: list<string>} */
    public function analyze(string $root): array
    {
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException('Application root must be an existing directory.');
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $findings = [];
        $files = [];
        foreach (['src', 'templates', 'config'] as $directory) {
            $path = $root . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($path)) {
                $findings[] = $this->finding('scan.missing_directory', 'review', $directory, 1, 'Expected application directory is missing; coverage is incomplete.');
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                static function (SplFileInfo $file) use ($root): bool {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                    return !$file->isLink() && !in_array($relative, [
                        'src/Console', 'src/Command', 'config/Migrations', 'config/Seeds', 'config/bootstrap_cli.php',
                    ], true);
                },
            ));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && !$file->isLink() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            $source = file_get_contents($file);
            if ($source === false) {
                throw new RuntimeException('Cannot read ' . $relative);
            }
            try {
                $nodes = $parser->parse($source) ?? [];
            } catch (Error $error) {
                $findings[] = $this->finding('scan.parse_error', 'review', $relative, $error->getStartLine(), 'PHP could not be parsed; review this file manually.');
                continue;
            }

            foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall
                || $node instanceof Node\Expr\FuncCall
                || $node instanceof Node\Expr\New_
                || $node instanceof Node\Expr\Variable) as $node) {
                $rule = $this->classify($node);
                if ($rule !== null) {
                    $findings[] = $this->finding($rule[0], $rule[1], $relative, $node->getStartLine(), $rule[2]);
                }
            }
        }

        return [
            'status' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'review')) > 0
                ? 'needs-review' : 'no-obvious-blockers',
            'files_scanned' => count($files),
            'findings' => $findings,
            'limitations' => [
                'Heuristic source inspection only; no result proves static deployment is safe.',
                'Does not execute requests, enumerate routes, inspect database records, or analyze JavaScript, vendor code, or external plugins.',
                'Standard CLI-only locations are excluded: src/Console, src/Command, config/Migrations, config/Seeds, and config/bootstrap_cli.php.',
                'Check authorization, private data, forms, APIs, headers, redirects, parameterized routes, assets, and data freshness before publishing.',
                'Method names are matched without type inference and can produce false positives; indirect and custom dynamic behavior can be missed.',
            ],
        ];
    }

    /** @return array{string, string, string}|null */
    private function classify(Node $node): ?array
    {
        if ($node instanceof Node\Expr\Variable) {
            return is_string($node->name) && in_array($node->name, ['_GET', '_POST', '_REQUEST', '_SESSION', '_COOKIE', '_FILES', '_SERVER'], true)
                ? ['request.superglobal', 'review', 'Request or session superglobal: determine whether output varies per visitor or request.'] : null;
        }
        if ($node instanceof Node\Expr\New_) {
            $class = $node->class instanceof Node\Name ? strtolower($node->class->getLast()) : '';
            return in_array($class, ['authenticationmiddleware', 'authorizationmiddleware', 'csrfprotectionmiddleware', 'session', 'sessionmiddleware'], true)
                ? ['middleware.stateful', 'review', 'Security or session service: determine which routes require a live backend.'] : null;
        }

        $name = $node->name ?? null;
        if (!$name instanceof Node\Identifier && !$name instanceof Node\Name) {
            return ['call.dynamic', 'review', 'Dynamic call target cannot be resolved by this analyzer.'];
        }
        $name = strtolower($name->toString());
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
            && strtolower($node->class->getLast()) === 'configure' && $name === 'write') {
            return ['configuration.write', 'info', 'Configuration write is normally build-time compatible; confirm it is not derived from visitor-specific state.'];
        }
        if ($node instanceof Node\Expr\FuncCall) {
            return in_array($name, ['time', 'date', 'gmdate', 'microtime', 'random_int', 'rand', 'mt_rand', 'session_start', 'setcookie', 'header'], true)
                ? ['runtime.function', 'review', 'Time-dependent, random, session, or response behavior needs a build-time/runtime decision.'] : null;
        }
        if (in_array($name, ['save', 'saveorfail', 'savemany', 'savemanyorfail', 'delete', 'deleteorfail', 'deleteall', 'deletemany', 'deletemanyorfail', 'updateall', 'insertquery', 'updatequery', 'deletequery', 'execute', 'query', 'send', 'deliver', 'write'], true)) {
            return ['runtime.side_effect', 'review', 'Possible database write, raw SQL, email, or other side effect requires a backend or removal.'];
        }
        if (in_array($name, ['getsession', 'getcookie', 'getcookieparams', 'getdata', 'getquery', 'getqueryparams', 'getuploadedfile', 'getuploadedfiles', 'getheader', 'getheaderline', 'getenv', 'is', 'allowmethod', 'allowmethods', 'getidentity', 'identify', 'setidentity', 'getattribute', 'loadcomponent', 'withcookie', 'withheader', 'withstatus', 'redirect', 'setmethods'], true)) {
            return ['request.context', 'review', 'Request-dependent, security, or response behavior: check whether a static page can preserve the required behavior.'];
        }
        if (in_array($name, ['find', 'findall', 'getconnection', 'connection', 'selectquery'], true)) {
            return ['data.read', 'info', 'Database read may be rendered at build time; publish only explicitly approved fields and plan rebuilds when data changes.'];
        }
        if ($name === 'create' && ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)) {
            $receiver = $node->var;
            if ($receiver instanceof Node\Expr\PropertyFetch && $receiver->name instanceof Node\Identifier && strtolower($receiver->name->toString()) === 'form') {
                return ['form.backend', 'review', 'Form detected: submission needs a service or backend unless it is entirely client-side.'];
            }
        }

        return null;
    }

    /** @return array{rule: string, severity: string, file: string, line: int, message: string} */
    private function finding(string $rule, string $severity, string $file, int $line, string $message): array
    {
        return compact('rule', 'severity', 'file', 'line', 'message');
    }
}
