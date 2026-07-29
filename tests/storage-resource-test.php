<?php

declare(strict_types=1);

use App\Storage\ResourceStore;
use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

final class StorageResourceTest
{
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function run(): void
    {
        echo "Running storage Resource tests...\n\n";
        $this->testCreateReadMetadataAndContent();
        $this->testReplaceCreatesNewResource();
        $this->testDeleteRemovesActiveContent();
        $this->report();
    }

    private function testCreateReadMetadataAndContent(): void
    {
        echo "Testing Resource create, metadata, and content... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Storage database is not configured for integration tests.');
            return;
        }

        try {
            $resource = $store->create('image/png; charset=binary', 'png-bytes', '123', 'storage.test');
            $content = $store->content($resource['id']);

            if ($content !== null
                && $content['bytes'] === 'png-bytes'
                && $resource['type'] === 'image/png'
                && $resource['length'] === 9
                && $resource['sha256'] === hash('sha256', 'png-bytes')
                && $resource['owner'] === 'member:123'
                && !array_key_exists('path', $resource)
                && !array_key_exists('storage_key', $resource)
            ) {
                $this->pass('Resource metadata and bytes matched canonical shape.');
                return;
            }

            $this->fail('Resource metadata or content did not match expected values.');
        } finally {
            $cleanup();
        }
    }

    private function testReplaceCreatesNewResource(): void
    {
        echo "Testing Resource replacement preserves immutable content... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Storage database is not configured for integration tests.');
            return;
        }

        try {
            $original = $store->create('text/plain', 'first', 'member:7', 'storage.test');
            $replacement = $store->replace($original['id'], 'text/plain', 'second', 'member:7', 'storage.test');
            $originalContent = $store->content($original['id']);
            $replacementContent = $store->content($replacement['id']);

            if ($original['id'] !== $replacement['id']
                && $replacement['replaces'] === $original['id']
                && $originalContent !== null
                && $replacementContent !== null
                && $originalContent['bytes'] === 'first'
                && $replacementContent['bytes'] === 'second'
            ) {
                $this->pass('Replacement created a new Resource without mutating the original.');
                return;
            }

            $this->fail('Replacement mutated the original or omitted replacement metadata.');
        } finally {
            $cleanup();
        }
    }

    private function testDeleteRemovesActiveContent(): void
    {
        echo "Testing Resource delete removes active retrieval... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Storage database is not configured for integration tests.');
            return;
        }

        try {
            $resource = $store->create('text/plain', 'delete-me', 'member:9', 'storage.test');
            $deleted = $store->delete($resource['id']);

            if ($deleted && $store->metadata($resource['id']) === null && $store->content($resource['id']) === null) {
                $this->pass('Deleted Resource is no longer retrievable.');
                return;
            }

            $this->fail('Deleted Resource remained active.');
        } finally {
            $cleanup();
        }
    }

    /**
     * @return array{0:ResourceStore|null,1:callable():void}
     */
    private function store(): array
    {
        $root = sys_get_temp_dir() . '/elonn-storage-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $config = $this->config();

        try {
            $database = $config['database'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $database['host'],
                $database['port'],
                $database['name'],
                $database['charset']
            );
            $pdo = new PDO($dsn, $database['username'], $database['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $migration = file_get_contents(BASE_PATH . '/migrations/001_create_storage_resources.sql');
            if (is_string($migration)) {
                $pdo->exec($migration);
            }
            $pdo->exec("DELETE FROM storage_resources WHERE created_by_service = 'storage.test'");
        } catch (Throwable) {
            $this->removeDirectory($root);
            return [null, static function (): void {
            }];
        }

        return [
            new ResourceStore($pdo, $root),
            function () use ($root, $pdo): void {
                $pdo->exec("DELETE FROM storage_resources WHERE created_by_service = 'storage.test'");
                $this->removeDirectory($root);
            },
        ];
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        if ($this->config === null) {
            Dotenv::createImmutable(BASE_PATH)->safeLoad();
            $this->config = require BASE_PATH . '/config/config.php';
        }

        return $this->config;
    }

    private function removeDirectory(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }

    private function pass(string $message): void
    {
        $this->passed++;
        echo "PASS: {$message}\n";
    }

    private function fail(string $message): void
    {
        $this->failed++;
        echo "FAIL: {$message}\n";
    }

    private function skip(string $message): void
    {
        $this->skipped++;
        echo "SKIP: {$message}\n";
    }

    private function report(): void
    {
        echo "\nPassed: {$this->passed}  Failed: {$this->failed}  Skipped: {$this->skipped}\n";
        if ($this->failed > 0) {
            exit(1);
        }
    }
}

(new StorageResourceTest())->run();
