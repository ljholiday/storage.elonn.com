<?php

declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Persists immutable Resource bytes and canonical Resource metadata.
 */
final class ResourceStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $resourcePath,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(string $mediaType, string $bytes, string $owner, string $createdByService, ?string $replaces = null): array
    {
        $mediaType = $this->normalizeMediaType($mediaType);
        $owner = $this->normalizeOwner($owner);
        $createdByService = $this->normalizeService($createdByService);
        if ($bytes === '') {
            throw new InvalidArgumentException('Resource content is required.');
        }
        if ($replaces !== null && !$this->validResourceId($replaces)) {
            throw new InvalidArgumentException('Replacement Resource id is invalid.');
        }

        $id = $this->newResourceId();
        $storageKey = $this->storageKey($id);
        $path = $this->absolutePath($storageKey);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Resource directory could not be created.');
        }
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Resource content could not be written.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $metadata = [
            'id' => $id,
            'media_type' => $mediaType,
            'byte_length' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'owner' => $owner,
            'created_by_service' => $createdByService,
            'replaces_resource_id' => $replaces,
            'storage_key' => $storageKey,
            'status' => 'active',
            'created_at' => $now,
            'modified_at' => $now,
            'deleted_at' => null,
        ];

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO storage_resources
                    (id, media_type, byte_length, sha256, owner, created_by_service, replaces_resource_id, storage_key, status, created_at, modified_at, deleted_at)
                 VALUES
                    (:id, :media_type, :byte_length, :sha256, :owner, :created_by_service, :replaces_resource_id, :storage_key, :status, :created_at, :modified_at, :deleted_at)'
            );
            $stmt->execute($metadata);
        } catch (\Throwable $throwable) {
            @unlink($path);
            throw $throwable;
        }

        return $this->canonical($metadata);
    }

    /** @return array<string, mixed>|null */
    public function metadata(string $id): ?array
    {
        if (!$this->validResourceId($id)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM storage_resources WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute(['id' => $id, 'status' => 'active']);
        $row = $stmt->fetch();

        return is_array($row) ? $this->canonical($row) : null;
    }

    /** @return array{metadata:array<string,mixed>,bytes:string}|null */
    public function content(string $id): ?array
    {
        $row = $this->row($id);
        if ($row === null) {
            return null;
        }

        $path = $this->absolutePath((string) $row['storage_key']);
        if (!is_file($path)) {
            throw new RuntimeException('Resource content is missing from Storage.');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Resource content could not be read.');
        }

        return [
            'metadata' => $this->canonical($row),
            'bytes' => $bytes,
        ];
    }

    /** @return array<string, mixed> */
    public function replace(string $id, string $mediaType, string $bytes, string $owner, string $createdByService): array
    {
        if ($this->metadata($id) === null) {
            throw new InvalidArgumentException('Resource not found.');
        }

        return $this->create($mediaType, $bytes, $owner, $createdByService, $id);
    }

    public function delete(string $id): bool
    {
        $row = $this->row($id);
        if ($row === null) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE storage_resources
             SET status = :status, modified_at = :modified_at, deleted_at = :deleted_at
             WHERE id = :id AND status = :active_status'
        );
        $stmt->execute([
            'status' => 'deleted',
            'modified_at' => $now,
            'deleted_at' => $now,
            'id' => $id,
            'active_status' => 'active',
        ]);

        @unlink($this->absolutePath((string) $row['storage_key']));

        return $stmt->rowCount() === 1;
    }

    public function storageReady(): bool
    {
        return is_dir($this->resourcePath) && is_writable($this->resourcePath);
    }

    /** @return array<string, mixed>|null */
    private function row(string $id): ?array
    {
        if (!$this->validResourceId($id)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM storage_resources WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute(['id' => $id, 'status' => 'active']);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function canonical(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'type' => (string) $row['media_type'],
            'length' => (int) $row['byte_length'],
            'sha256' => (string) $row['sha256'],
            'owner' => (string) $row['owner'],
            'created' => $this->isoTime((string) $row['created_at']),
            'modified' => $this->isoTime((string) $row['modified_at']),
            'url' => null,
            'replaces' => $row['replaces_resource_id'] === null ? null : (string) $row['replaces_resource_id'],
        ];
    }

    private function normalizeMediaType(string $mediaType): string
    {
        $mediaType = strtolower(trim(explode(';', $mediaType)[0]));
        if ($mediaType === '' || preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $mediaType) !== 1) {
            throw new InvalidArgumentException('Valid Content-Type is required.');
        }

        return $mediaType;
    }

    private function normalizeOwner(string $owner): string
    {
        $owner = trim($owner);
        if ($owner === '') {
            throw new InvalidArgumentException('Resource owner is required.');
        }

        return str_starts_with($owner, 'member:') || str_starts_with($owner, 'service:')
            ? $owner
            : 'member:' . $owner;
    }

    private function normalizeService(string $service): string
    {
        $service = trim($service);
        if ($service === '') {
            throw new InvalidArgumentException('Creating service is required.');
        }

        return $service;
    }

    private function newResourceId(): string
    {
        return 'resource:' . bin2hex(random_bytes(16));
    }

    private function validResourceId(string $id): bool
    {
        return preg_match('/^resource:[a-f0-9]{32}$/', $id) === 1;
    }

    private function storageKey(string $id): string
    {
        $key = substr($id, strlen('resource:'));
        return substr($key, 0, 2) . '/' . $key . '/original';
    }

    private function absolutePath(string $storageKey): string
    {
        return rtrim($this->resourcePath, '/') . '/' . ltrim($storageKey, '/');
    }

    private function isoTime(string $time): string
    {
        return str_replace(' ', 'T', $time) . 'Z';
    }
}
