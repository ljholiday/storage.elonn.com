<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Security\ServiceAuthenticator;
use App\Storage\Database;
use App\Storage\ResourceStore;
use InvalidArgumentException;
use Throwable;

/**
 * Wires Storage routes to Resource persistence.
 */
final class Application
{
    private Router $router;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->router = new Router();
        $this->routes();
    }

    public function handle(): void
    {
        $this->router->dispatch(Request::fromGlobals())->send();
    }

    private function routes(): void
    {
        $this->router->get('/health', fn (): Response => Response::json([
            'status' => 'ok',
            'service' => 'elonn_storage',
        ]));

        $this->router->get('/ready', function (): Response {
            $dependencies = [
                'database' => 'error',
                'resource_storage' => 'error',
            ];

            try {
                $pdo = Database::pdo($this->config);
                $pdo->query('SELECT 1');
                $dependencies['database'] = Database::schemaReady($pdo) ? 'connected' : 'schema_missing';
            } catch (Throwable $throwable) {
                error_log('[storage] /ready failed: ' . $throwable->getMessage());
            }

            $resourcePath = (string) $this->config['storage']['resource_path'];
            $dependencies['resource_storage'] = is_dir($resourcePath) && is_writable($resourcePath) ? 'connected' : 'error';

            $ready = $dependencies['database'] === 'connected' && $dependencies['resource_storage'] === 'connected';

            return Response::json([
                'status' => $ready ? 'ready' : 'not_ready',
                'service' => 'elonn_storage',
                'dependencies' => $dependencies,
            ], $ready ? 200 : 500);
        });

        $this->router->get('/metrics', function (Request $request): Response {
            $startedAt = microtime(true);
            $caller = $this->authenticatedService($request);
            if ($caller !== 'admin.elonn') {
                return $this->serviceAuthFailure();
            }

            $database = 'error';
            try {
                $pdo = Database::pdo($this->config);
                $pdo->query('SELECT 1');
                $database = Database::schemaReady($pdo) ? 'connected' : 'schema_missing';
            } catch (Throwable $throwable) {
                error_log('[storage] /metrics database check failed: ' . $throwable->getMessage());
            }

            $resourcePath = (string) $this->config['storage']['resource_path'];
            $resourceStorage = is_dir($resourcePath) && is_writable($resourcePath) ? 'connected' : 'error';

            return Response::json([
                'contract_version' => '1.0',
                'service' => 'storage.elonn',
                'status' => $database === 'connected' && $resourceStorage === 'connected' ? 'ok' : 'degraded',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'response_time_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'custom_metrics' => [
                    'database' => $database,
                    'resource_storage' => $resourceStorage,
                ],
            ]);
        });

        $this->router->get('/', fn (): Response => Response::json([
            'service' => 'elonn_storage',
            'description' => 'Elonn Resource byte service.',
            'owns' => [
                'Resource bytes',
                'Resource metadata',
                'Resource integrity digests',
            ],
            'does_not_own' => [
                'Paint documents',
                'Social photos',
                'Maps field data',
                'World Objects',
                'application object meaning',
            ],
        ]));

        $this->router->post('/resources', function (Request $request): Response {
            $service = $this->authenticatedService($request);
            if ($service === null) {
                return $this->serviceAuthFailure();
            }

            return $this->writeResource($request, $service, null);
        });

        $this->router->put('/resources/{id}', function (Request $request, array $params): Response {
            $service = $this->authenticatedService($request);
            if ($service === null) {
                return $this->serviceAuthFailure();
            }

            return $this->writeResource($request, $service, $params['id'] ?? null);
        });

        $this->router->get('/resources', function (Request $request): Response {
            if ($this->authenticatedService($request) === null) {
                return $this->serviceAuthFailure();
            }

            $owner = trim((string) $request->query('owner'));
            if ($owner === '') {
                return Response::json(['error' => 'owner query parameter is required.'], 422);
            }

            try {
                return Response::json(['resources' => $this->store()->listByOwner($owner)]);
            } catch (InvalidArgumentException $exception) {
                return Response::json(['error' => $exception->getMessage()], 422);
            } catch (Throwable $throwable) {
                error_log('[storage] list failed: ' . $throwable->getMessage());
                return Response::json(['error' => 'Unable to list Resources.'], 500);
            }
        });

        $this->router->get('/resources/{id}/metadata', function (Request $request, array $params): Response {
            if ($this->authenticatedService($request) === null) {
                return $this->serviceAuthFailure();
            }

            try {
                $metadata = $this->store()->metadata((string) ($params['id'] ?? ''));
                if ($metadata === null) {
                    return Response::json(['error' => 'Resource not found.'], 404);
                }

                return Response::json(['resource' => $metadata]);
            } catch (Throwable $throwable) {
                error_log('[storage] metadata failed: ' . $throwable->getMessage());
                return Response::json(['error' => 'Unable to retrieve Resource metadata.'], 500);
            }
        });

        $this->router->get('/resources/{id}/content', function (Request $request, array $params): Response {
            if ($this->authenticatedService($request) === null) {
                return $this->serviceAuthFailure();
            }

            try {
                $content = $this->store()->content((string) ($params['id'] ?? ''));
                if ($content === null) {
                    return Response::json(['error' => 'Resource not found.'], 404);
                }

                return Response::binary($content['bytes'], (string) $content['metadata']['type']);
            } catch (Throwable $throwable) {
                error_log('[storage] content failed: ' . $throwable->getMessage());
                return Response::json(['error' => 'Unable to retrieve Resource content.'], 500);
            }
        });

        $this->router->delete('/resources/{id}', function (Request $request, array $params): Response {
            if ($this->authenticatedService($request) === null) {
                return $this->serviceAuthFailure();
            }

            try {
                if (!$this->store()->delete((string) ($params['id'] ?? ''))) {
                    return Response::json(['error' => 'Resource not found.'], 404);
                }

                return Response::json(['deleted' => true]);
            } catch (Throwable $throwable) {
                error_log('[storage] delete failed: ' . $throwable->getMessage());
                return Response::json(['error' => 'Unable to delete Resource.'], 500);
            }
        });

        $this->router->post('/storage/call', function (Request $request): Response {
            if ($this->authenticatedService($request) === null) {
                return $this->datasetError('storage.service_auth_failed', 'auth', 'Storage service authentication failed.', 401);
            }

            $call = $request->parsedBody();
            $operation = (string) ($call['content']['operation'] ?? '');
            $resourceId = (string) ($call['content']['resource_id'] ?? $call['content']['id'] ?? '');

            if ($operation === 'storage.resource.metadata') {
                $metadata = $this->store()->metadata($resourceId);
                if ($metadata === null) {
                    return $this->datasetError('storage.resource_not_found', 'not_found', 'Resource not found.', 404);
                }

                return Response::json($this->dataset([$metadata]));
            }

            if ($operation === 'storage.resource.delete') {
                if (!$this->store()->delete($resourceId)) {
                    return $this->datasetError('storage.resource_not_found', 'not_found', 'Resource not found.', 404);
                }

                return Response::json($this->dataset([], [['id' => $resourceId, 'type' => 'storage.resource.deleted']]));
            }

            return $this->datasetError('storage.unsupported_operation', 'invalid_call', 'Storage operation is not supported.', 422);
        });
    }

    private function writeResource(Request $request, string $service, ?string $replaces): Response
    {
        try {
            $owner = trim($request->header('x-elonn-member-id'));
            if ($owner === '') {
                $owner = 'service:' . $service;
            }

            $resource = $replaces === null
                ? $this->store()->create($request->header('content-type'), $request->body(), $owner, $service)
                : $this->store()->replace($replaces, $request->header('content-type'), $request->body(), $owner, $service);

            return Response::json(['resource' => $resource], 201);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $throwable) {
            error_log('[storage] write failed: ' . $throwable->getMessage());
            return Response::json(['error' => 'Unable to store Resource.'], 500);
        }
    }

    private function authenticatedService(Request $request): ?string
    {
        /** @var array<string, string> $tokens */
        $tokens = $this->config['service_auth'];

        return (new ServiceAuthenticator($tokens))->authenticate($request);
    }

    private function serviceAuthFailure(): Response
    {
        return Response::json([
            'errors' => [[
                'code' => 'storage.service_auth_failed',
                'class' => 'auth',
                'message' => 'Storage service authentication failed.',
            ]],
        ], 401);
    }

    private function store(): ResourceStore
    {
        return new ResourceStore(Database::pdo($this->config), (string) $this->config['storage']['resource_path']);
    }

    /** @param array<int, array<string, mixed>> $resources @param array<int, array<string, mixed>> $objects */
    private function dataset(array $resources, array $objects = []): array
    {
        return [
            'type' => 'service',
            'service' => 'storage',
            'resources' => $resources,
            'objects' => $objects,
            'errors' => [],
        ];
    }

    private function datasetError(string $code, string $class, string $message, int $status): Response
    {
        return Response::json([
            'type' => 'service',
            'service' => 'storage',
            'resources' => [],
            'objects' => [],
            'errors' => [[
                'code' => $code,
                'class' => $class,
                'message' => $message,
            ]],
        ], $status);
    }
}
