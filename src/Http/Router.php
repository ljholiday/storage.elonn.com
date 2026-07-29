<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Small method/path router with named path parameters.
 */
final class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:callable(Request,array<string,string>):Response}>> */
    private array $routes = [];

    /** @param callable(Request,array<string,string>): Response $handler */
    public function get(string $pattern, callable $handler): void
    {
        $this->routes['GET'][] = ['pattern' => $this->normalizePath($pattern), 'handler' => $handler];
    }

    /** @param callable(Request,array<string,string>): Response $handler */
    public function post(string $pattern, callable $handler): void
    {
        $this->routes['POST'][] = ['pattern' => $this->normalizePath($pattern), 'handler' => $handler];
    }

    /** @param callable(Request,array<string,string>): Response $handler */
    public function put(string $pattern, callable $handler): void
    {
        $this->routes['PUT'][] = ['pattern' => $this->normalizePath($pattern), 'handler' => $handler];
    }

    /** @param callable(Request,array<string,string>): Response $handler */
    public function delete(string $pattern, callable $handler): void
    {
        $this->routes['DELETE'][] = ['pattern' => $this->normalizePath($pattern), 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        $path = $this->normalizePath($request->path());
        foreach ($this->routes[$request->method()] ?? [] as $route) {
            $params = $this->match($route['pattern'], $path);
            if ($params !== null) {
                return ($route['handler'])($request, $params);
            }
        }

        return Response::json(['error' => 'Not found.'], 404);
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
        if ($regex === null || preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode((string) $value);
            }
        }

        return $params;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $normalized = rtrim($path, '/');
        return $normalized === '' ? '/' : $normalized;
    }
}
