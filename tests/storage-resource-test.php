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
        $this->testCreateAssignsContentDerivedAddress();
        $this->testDuplicateContentDedups();
        $this->testOldFormatIdsStillResolve();
        $this->testReuploadAfterDeleteRevivesSameAddress();
        $this->testListByOwner();
        $this->report();
    }

    private function testCreateAssignsContentDerivedAddress(): void
    {
        echo "Testing Resource id is derived from content... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Storage database is not configured for integration tests.');
            return;
        }

        try {
            $resource = $store->create('text/plain', 'addressed-by-content', 'member:1', 'storage.test');
            $expected = 'storage.elonn:sha256:' . hash('sha256', 'addressed-by-content');

            if ($resource['id'] === $expected) {
                $this->pass('Resource id matched storage.elonn:sha256:<hash of bytes>.');
                return;
            }

            $this->fail('Resource id was not derived from content: ' . $resource['id']);
        } finally {
            $cleanup();
        }
    }

    private function testDuplicateContentDedups(): void
    {
        echo "Testing identical content dedups to one Resource... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Storage database is not configured for integration tests.');
            return;
        }

        try {
            $first = $store->create('text/plain', 'same-bytes-twice', 'member:2', 'storage.test');
            $second = $store->create('text/plain', 'same-bytes-twice', 'member:3', 'storage.test');
            $count = (int) $this->pdo($store)
                ->query("SELECT COUNT(*) FROM storage_resources WHERE created_by_service = 'storage.test' AND sha256 = '" . hash('sha256', 'same-bytes-twice') . "'")
                ->fetchColumn();

            if ($first['id'] === $second['id'] && $count === 1) {
                $this->pass('Uploading identical bytes twice returned the same address and one row.');
                return;
            }

            $this->fail('Duplicate content did not dedup: ids ' . $first['id'] . ' / ' . $second['id'] . ", rows {$count}.");
        } finally {
            $cleanup();
        }
    }

    private function testOldFormatIdsStillResolve(): void
    {
        echo "Testing pre-existing resource:<hex> ids still resolve... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Storage database is not configured for integration tests.');
            return;
        }

        try {
            $legacyId = 'resource:' . bin2hex(random_bytes(16));
            $this->insertLegacyRow($store, $legacyId, 'legacy-bytes');

            $metadata = $store->metadata($legacyId);
            $content = $store->content($legacyId);

            if ($metadata !== null && $content !== null && $content['bytes'] === 'legacy-bytes') {
                $this->pass('Legacy resource:<hex> id resolved metadata and content unchanged.');
                return;
            }

            $this->fail('Legacy-format Resource id failed to resolve after the addressing change.');
        } finally {
            $cleanup();
        }
    }

    private function testReuploadAfterDeleteRevivesSameAddress(): void
    {
        echo "Testing re-upload of deleted content revives the same address... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Storage database is not configured for integration tests.');
            return;
        }

        try {
            $resource = $store->create('text/plain', 'revive-me', 'member:4', 'storage.test');
            $store->delete($resource['id']);

            if ($store->metadata($resource['id']) !== null) {
                $this->fail('Resource remained retrievable immediately after delete.');
                return;
            }

            $revived = $store->create('text/plain', 'revive-me', 'member:4', 'storage.test');
            $content = $store->content($revived['id']);

            if ($revived['id'] === $resource['id'] && $content !== null && $content['bytes'] === 'revive-me') {
                $this->pass('Re-uploading previously deleted content revived the same address.');
                return;
            }

            $this->fail('Re-upload after delete did not revive the same address correctly.');
        } finally {
            $cleanup();
        }
    }

    private function testListByOwner(): void
    {
        echo "Testing listByOwner returns only that owner's active Resources... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Storage database is not configured for integration tests.');
            return;
        }

        try {
            $mine = $store->create('text/plain', 'owner-a-1', 'member:list-a', 'storage.test');
            $store->create('text/plain', 'owner-a-2', 'member:list-a', 'storage.test');
            $other = $store->create('text/plain', 'owner-b-1', 'member:list-b', 'storage.test');
            $deleted = $store->create('text/plain', 'owner-a-3', 'member:list-a', 'storage.test');
            $store->delete($deleted['id']);

            $listed = $store->listByOwner('member:list-a');
            $ids = array_column($listed, 'id');

            if (count($listed) === 2
                && in_array($mine['id'], $ids, true)
                && !in_array($other['id'], $ids, true)
                && !in_array($deleted['id'], $ids, true)
            ) {
                $this->pass('listByOwner returned exactly that owner\'s active Resources.');
                return;
            }

            $this->fail('listByOwner did not scope correctly to owner and active status.');
        } finally {
            $cleanup();
        }
    }

    private function insertLegacyRow(ResourceStore $store, string $id, string $bytes): void
    {
        $pdo = $this->pdo($store);
        $key = substr($id, strlen('resource:'));
        $storageKey = substr($key, 0, 2) . '/' . $key . '/original';
        $root = $this->rootFor($store);
        $path = rtrim($root, '/') . '/' . $storageKey;
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $bytes);

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO storage_resources
                (id, media_type, byte_length, sha256, owner, created_by_service, replaces_resource_id, storage_key, status, created_at, modified_at, deleted_at)
             VALUES
                (:id, :media_type, :byte_length, :sha256, :owner, :created_by_service, NULL, :storage_key, :status, :created_at, :modified_at, NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'media_type' => 'text/plain',
            'byte_length' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'owner' => 'member:legacy',
            'created_by_service' => 'storage.test',
            'storage_key' => $storageKey,
            'status' => 'active',
            'created_at' => $now,
            'modified_at' => $now,
        ]);
    }

    private function pdo(ResourceStore $store): \PDO
    {
        $property = new \ReflectionProperty(ResourceStore::class, 'pdo');
        $property->setAccessible(true);

        return $property->getValue($store);
    }

    private function rootFor(ResourceStore $store): string
    {
        $property = new \ReflectionProperty(ResourceStore::class, 'resourcePath');
        $property->setAccessible(true);

        return $property->getValue($store);
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
            foreach (['001_create_storage_resources.sql', '002_widen_resource_id_columns.sql'] as $file) {
                $migration = file_get_contents(BASE_PATH . '/migrations/' . $file);
                if (is_string($migration)) {
                    $pdo->exec($migration);
                }
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
