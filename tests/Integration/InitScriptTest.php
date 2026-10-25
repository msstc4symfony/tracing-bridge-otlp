<?php

declare(strict_types=1);

namespace Msstc4Symfony\SkeletonBundle\Test\Integration;

use Override;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InitScriptTest extends TestCase
{
    private string $work;

    #[Override]
    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/bundle-init-' . bin2hex(random_bytes(6));
        $repo = dirname(__DIR__, 2);
        foreach (array_filter(explode("\0", $this->sh('git ls-files -z', $repo)), static fn (string $file): bool => $file !== '') as $file) {
            $target = $this->work . '/repo/' . $file;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0o777, true);
            }
            copy($repo . '/' . $file, $target);
        }
        $this->commitAll('template');

        // A PATH with git but without composer, so the script takes its "print the command" branch.
        mkdir($this->work . '/bin');
        symlink(trim($this->sh('command -v git', $this->work)), $this->work . '/bin/git');
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->sh('rm -rf ' . escapeshellarg($this->work), sys_get_temp_dir());
    }

    public function testInitialisesTheBundle(): void
    {
        [$code, $output] = $this->init('OutboxInbox', 'Transactional outbox for Messenger');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('COMPOSER=composer-ci.json composer update --lock', $output);

        $bundle = $this->read('src/OutboxInboxBundle.php');
        self::assertStringContainsString('namespace Msstc4Symfony\OutboxInboxBundle;', $bundle);
        self::assertStringContainsString('final class OutboxInboxBundle extends AbstractBundle', $bundle);
        self::assertStringContainsString("'msstc4symfony_outbox_inbox'", $bundle);
        self::assertFileExists($this->work . '/repo/tests/Integration/OutboxInboxBundleTest.php');

        $composer = $this->json('composer.json');
        self::assertSame('msstc4symfony/outbox-inbox-bundle', $composer['name']);
        self::assertSame('Transactional outbox for Messenger', $composer['description']);
        self::assertSame('Transactional outbox for Messenger (CI profile — CI-only tools)', $this->json('composer-ci.json')['description']);

        self::assertSame("# Changelog\n\n## Unreleased\n", $this->read('CHANGELOG.md'));
        self::assertStringNotContainsString('init:start', $this->read('README.md'));
        self::assertStringContainsString("\n\nTransactional outbox for Messenger\n\n## Compatibility\n", $this->read('README.md'));
        self::assertStringContainsString('Transactional outbox for Messenger', $this->read('CLAUDE.md'));
        self::assertStringNotContainsString('init-smoke', $this->read('.github/workflows/checks.yml'));
        self::assertStringContainsString('value: ^Msstc4Symfony\\\\OutboxInboxBundle\\\\OutboxInboxBundle$', $this->read('deptrac.yaml'));
        self::assertFileDoesNotExist($this->work . '/repo/bin/init');
        self::assertFileDoesNotExist($this->work . '/repo/tests/Integration/InitScriptTest.php');

        foreach ($this->lines($this->sh('git ls-files -co --exclude-standard', $this->work . '/repo')) as $file) {
            if ($file === 'composer-ci.lock') {
                continue;
            }
            self::assertStringNotContainsStringIgnoringCase('skeleton', $file);
            self::assertStringNotContainsStringIgnoringCase('skeleton', $this->read($file), $file);
        }
    }

    #[TestWith(['HttpClient', 'http-client', 'msstc4symfony_http_client'])]
    #[TestWith(['HTTPClient', 'http-client', 'msstc4symfony_http_client'])]
    #[TestWith(['Outbox2', 'outbox2', 'msstc4symfony_outbox2'])]
    public function testDerivesKebabAndSnakeNames(string $name, string $kebab, string $alias): void
    {
        [$code, $output] = $this->init($name, 'Something');

        self::assertSame(0, $code, $output);
        self::assertSame('msstc4symfony/' . $kebab . '-bundle', $this->json('composer.json')['name']);
        self::assertStringContainsString("'" . $alias . "'", $this->read('src/' . $name . 'Bundle.php'));
    }

    public function testDescriptionIsJsonEscaped(): void
    {
        [$code, $output] = $this->init('Quote', 'Say "hi" \ then leave');

        self::assertSame(0, $code, $output);
        self::assertSame('Say "hi" \ then leave', $this->json('composer.json')['description']);
        self::assertStringContainsString('Say "hi" \ then leave', $this->read('README.md'));
    }

    #[TestWith(['outbox', 'Ok'])]
    #[TestWith(['OutboxBundle', 'Ok'])]
    #[TestWith(['Out-box', 'Ok'])]
    #[TestWith(['', 'Ok'])]
    #[TestWith(['Outbox', ''])]
    #[TestWith(['Outbox', '   '])]
    #[TestWith(['Outbox', "Two\nlines"])]
    #[TestWith(['Outbox', "bad\xff"])]
    #[TestWith(['SkeletonCache', 'Ok'])]
    #[TestWith(['Outbox', 'A skeleton thing'])]
    public function testRejectsInvalidArgumentsWithoutTouchingTheTree(string $name, string $description): void
    {
        [$code] = $this->init($name, $description);

        self::assertSame(1, $code);
        self::assertSame('', $this->sh('git status --porcelain', $this->work . '/repo'));
    }

    public function testRefusesADirtyTree(): void
    {
        file_put_contents($this->work . '/repo/CHANGELOG.md', "dirty\n");

        [$code] = $this->init('Outbox', 'Ok');

        self::assertSame(1, $code);
        self::assertSame(' M CHANGELOG.md', $this->sh('git status --porcelain', $this->work . '/repo'));
        self::assertSame("dirty\n", $this->read('CHANGELOG.md'));
    }

    public function testRewritesAPathContainingANewline(): void
    {
        mkdir($this->work . '/repo/docs');
        file_put_contents($this->work . "/repo/docs/odd \nSkeletonBundle.md", "See SkeletonBundle.\n");
        $this->commitAll('odd path');

        [$code, $output] = $this->init('Outbox', 'Ok');

        self::assertSame(0, $code, $output);
        self::assertSame("See OutboxBundle.\n", $this->read("docs/odd \nOutboxBundle.md"));
    }

    public function testFailsBeforeRewritingWithoutThePartialInitHint(): void
    {
        $git = trim($this->sh('command -v git', $this->work));
        unlink($this->work . '/bin/git');
        file_put_contents($this->work . '/bin/git', "#!/bin/sh\n[ \"$1\" = ls-files ] && exit 1\nexec " . escapeshellarg($git) . " \"$@\"\n");
        chmod($this->work . '/bin/git', 0o755);

        [$code, $output] = $this->init('Outbox', 'Ok');

        self::assertSame(1, $code);
        self::assertStringContainsString('git ls-files -z failed.', $output);
        self::assertStringNotContainsString('partially initialised', $output);
        self::assertSame('', $this->sh('git status --porcelain', $this->work . '/repo'));
    }

    public function testFailsOnALeftoverMention(): void
    {
        mkdir($this->work . '/repo/docs');
        file_put_contents($this->work . '/repo/docs/note.md', "Copied from the Skeleton.\n");
        $this->commitAll('note');

        [$code, $output] = $this->init('Outbox', 'Ok');

        self::assertSame(1, $code);
        self::assertStringContainsString('docs/note.md', $output);
    }

    /**
     * @return array{int, string}
     */
    private function init(string $name, string $description): array
    {
        // A file, not a pipe: draining two pipes one after another can deadlock on a full buffer.
        $errors = $this->work . '/stderr';
        $process = proc_open(
            [PHP_BINARY, 'bin/init', $name, $description],
            [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
            $this->work . '/repo',
            ['PATH' => $this->work . '/bin', 'HOME' => $this->work],
        );
        if ($process === false) {
            throw new RuntimeException('Cannot start bin/init.');
        }
        $stdout = $pipes[1] ?? null;
        self::assertIsResource($stdout);
        $output = stream_get_contents($stdout);
        fclose($stdout);
        $code = proc_close($process);
        $errorOutput = file_get_contents($errors);
        self::assertIsString($output);
        self::assertIsString($errorOutput);

        return [$code, $output . $errorOutput];
    }

    private function commitAll(string $message): void
    {
        $repo = $this->work . '/repo';
        $this->sh('git init -q 2>/dev/null; git add -A && git -c user.name=t -c user.email=t@localhost commit -qm ' . escapeshellarg($message), $repo);
    }

    private function sh(string $command, string $cwd): string
    {
        exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException($command . ': ' . implode("\n", $output));
        }

        return implode("\n", $output);
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(explode("\n", $output), static fn (string $line): bool => $line !== ''));
    }

    private function read(string $file): string
    {
        $content = file_get_contents($this->work . '/repo/' . $file);
        self::assertIsString($content, $file);

        return $content;
    }

    /**
     * @return array{name: string, description: string}
     */
    private function json(string $file): array
    {
        $decoded = json_decode($this->read($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['name'] ?? null);
        self::assertIsString($decoded['description'] ?? null);

        return ['name' => $decoded['name'], 'description' => $decoded['description']];
    }
}
