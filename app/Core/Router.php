<?php
declare(strict_types=1);

namespace School\Core;

final class Router
{
    /** @var array<string, callable(Request):Response> */
    private array $routes = [];
    public function get(string $path, callable $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void { $this->add('POST', $path, $handler); }
    public function add(string $method, string $path, callable $handler): void
    {
        $key = strtoupper($method) . ' ' . '/' . trim($path, '/');
        $this->routes[$key] = $handler;
    }
    public function dispatch(Request $request): Response
    {
        $key = $request->method() . ' ' . $request->path();
        if (!isset($this->routes[$key])) return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        return ($this->routes[$key])($request);
    }
}
