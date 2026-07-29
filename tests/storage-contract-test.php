<?php

declare(strict_types=1);

final class StorageContractTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "Running storage contract tests...\n\n";
        $this->testContractDefinesResourceBoundaries();
        $this->testResourceDocPreservesImmutability();
        $this->report();
    }

    private function testContractDefinesResourceBoundaries(): void
    {
        echo "Testing Storage contract owns Resources only... ";
        $contract = file_get_contents(dirname(__DIR__, 2) . '/dev.elonn.local/public/contracts/services/storage-contract.md');
        if (is_string($contract)
            && str_contains($contract, 'Storage is the Resource byte service for Elonn.')
            && str_contains($contract, 'Storage does not own Paint documents')
            && str_contains($contract, 'Storage never returns internal filesystem paths')
        ) {
            $this->pass('Storage contract defines the Resource boundary.');
            return;
        }

        $this->fail('Storage contract did not define the required boundary.');
    }

    private function testResourceDocPreservesImmutability(): void
    {
        echo "Testing Resource doc preserves immutable identifiers... ";
        $resource = file_get_contents(dirname(__DIR__, 2) . '/dev.elonn.local/public/canonical/resource.md');
        if (is_string($resource)
            && str_contains($resource, 'Resources are immutable.')
            && str_contains($resource, 'Replacing content creates a new Resource.')
            && str_contains($resource, 'must not expose Storage')
        ) {
            $this->pass('Resource doc preserves immutable Resource semantics.');
            return;
        }

        $this->fail('Resource doc did not preserve immutable Resource semantics.');
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

    private function report(): void
    {
        echo "\nPassed: {$this->passed}  Failed: {$this->failed}\n";
        if ($this->failed > 0) {
            exit(1);
        }
    }
}

(new StorageContractTest())->run();
