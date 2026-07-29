<?php

declare(strict_types=1);

use App\Http\Request;
use App\Security\ServiceAuthenticator;

require dirname(__DIR__) . '/vendor/autoload.php';

final class StorageSecurityTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "Running storage security tests...\n\n";
        $this->testBearerTokenAuthenticatesService();
        $this->testMismatchedTokenFails();
        $this->report();
    }

    private function testBearerTokenAuthenticatesService(): void
    {
        echo "Testing bearer token service authentication... ";
        $auth = new ServiceAuthenticator(['paint.elonn' => 'secret']);
        $request = $this->request([
            'x-elonn-service' => 'paint.elonn',
            'authorization' => 'Bearer secret',
        ]);

        if ($auth->authenticate($request) === 'paint.elonn') {
            $this->pass('Service token authenticated.');
            return;
        }

        $this->fail('Valid service token was rejected.');
    }

    private function testMismatchedTokenFails(): void
    {
        echo "Testing mismatched token is rejected... ";
        $auth = new ServiceAuthenticator(['paint.elonn' => 'secret']);
        $request = $this->request([
            'x-elonn-service' => 'paint.elonn',
            'authorization' => 'Bearer wrong',
        ]);

        if ($auth->authenticate($request) === null) {
            $this->pass('Mismatched service token was rejected.');
            return;
        }

        $this->fail('Mismatched service token authenticated.');
    }

    /** @param array<string, string> $headers */
    private function request(array $headers): Request
    {
        $reflection = new ReflectionClass(Request::class);
        $constructor = $reflection->getConstructor();
        assert($constructor !== null);
        $constructor->setAccessible(true);

        $request = $reflection->newInstanceWithoutConstructor();
        $constructor->invokeArgs($request, [
            'GET',
            '/',
            $headers,
            [],
            '',
            [],
        ]);

        return $request;
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

(new StorageSecurityTest())->run();
