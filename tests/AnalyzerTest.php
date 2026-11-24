<?php
declare(strict_types=1);

namespace CakephpToStatic\Test;

use CakephpToStatic\Analysis\Analyzer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AnalyzerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cakephp-to-static-' . bin2hex(random_bytes(8));
        foreach (['src', 'templates', 'config'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700, true);
        }
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testReadOnlyDataIsNotAutomaticallyABlocker(): void
    {
        $this->source('src/People.php', '<?php $people = $this->People->find()->all();');
        $report = (new Analyzer())->analyze($this->root);

        self::assertSame('no-obvious-blockers', $report['status']);
        self::assertSame(1, $report['files_scanned']);
        self::assertSame('data.read', $report['findings'][0]['rule']);
        self::assertSame('src/People.php', $report['findings'][0]['file']);
        self::assertNotEmpty($report['limitations']);
    }

    public function testDetectsWritesRequestStateFormsAndSecurity(): void
    {
        $this->source('src/Controller.php', <<<'PHP'
<?php
$this->People->save($person);
$this->request->getSession();
$this->request?->getQuery('page');
$this->request->getData();
$this->Form->create($person);
new AuthenticationMiddleware($app);
echo $_COOKIE['name'];
time();
$this->{$action}();
PHP);
        $report = (new Analyzer())->analyze($this->root);
        $rules = array_column($report['findings'], 'rule');

        self::assertSame('needs-review', $report['status']);
        foreach (['runtime.side_effect', 'request.context', 'form.backend', 'middleware.stateful', 'request.superglobal', 'runtime.function', 'call.dynamic'] as $rule) {
            self::assertContains($rule, $rules);
        }
        self::assertSame(2, $report['findings'][0]['line']);
    }

    public function testIgnoresCommentsStringsAndNonApplicationDirectories(): void
    {
        $this->source('templates/page.php', <<<'PHP'
<?php
// $table->save($entity);
echo '$request->getSession()';
?>
<p>save($entity) is plain HTML.</p>
PHP);
        mkdir($this->root . '/vendor');
        $this->source('vendor/ignored.php', '<?php session_start();');
        $report = (new Analyzer())->analyze($this->root);

        self::assertSame([], $report['findings']);
        self::assertSame(1, $report['files_scanned']);
    }

    public function testReportsInvalidPhpWithoutLeakingSource(): void
    {
        $this->source('config/broken.php', '<?php SECRET_VALUE {');
        $report = (new Analyzer())->analyze($this->root);

        self::assertSame('needs-review', $report['status']);
        self::assertSame('scan.parse_error', $report['findings'][0]['rule']);
        self::assertStringNotContainsString('SECRET_VALUE', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testMissingDirectoriesAreNotReportedAsClean(): void
    {
        rmdir($this->root . '/templates');
        $report = (new Analyzer())->analyze($this->root);

        self::assertSame('needs-review', $report['status']);
        self::assertSame('scan.missing_directory', $report['findings'][0]['rule']);
    }

    public function testRejectsMissingRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Analyzer())->analyze($this->root . '/missing');
    }

    public function testReportsAreDeterministic(): void
    {
        $this->source('src/Z.php', '<?php $table->save($entity);');
        $this->source('src/A.php', '<?php $request->getData();');
        $analyzer = new Analyzer();

        self::assertSame($analyzer->analyze($this->root), $analyzer->analyze($this->root));
        self::assertSame('src/A.php', $analyzer->analyze($this->root)['findings'][0]['file']);
    }

    public function testExcludesConventionalCliOnlyCode(): void
    {
        foreach (['src/Console', 'src/Command', 'config/Migrations', 'config/Seeds'] as $directory) {
            mkdir($this->root . '/' . $directory);
            $this->source($directory . '/Example.php', '<?php $table->save($entity);');
        }
        $this->source('config/bootstrap_cli.php', '<?php $table->save($entity);');
        $report = (new Analyzer())->analyze($this->root);

        self::assertSame([], $report['findings']);
        self::assertSame(0, $report['files_scanned']);
    }

    public function testConfigurationWritesAreInformationalButCacheWritesNeedReview(): void
    {
        $this->source('config/bootstrap.php', '<?php Configure::write("debug", false); Cache::write("key", $value);');
        $report = (new Analyzer())->analyze($this->root);

        self::assertSame('configuration.write', $report['findings'][0]['rule']);
        self::assertSame('info', $report['findings'][0]['severity']);
        self::assertSame('runtime.side_effect', $report['findings'][1]['rule']);
        self::assertSame('needs-review', $report['status']);
    }

    private function source(string $path, string $source): void
    {
        file_put_contents($this->root . '/' . $path, $source);
    }
}
