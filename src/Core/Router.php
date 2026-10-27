<?php

declare(strict_types=1);

namespace RecupBuild\Core;

/**
 * The routes of the app and who answers them: a method, a path and a controller
 * action, with no framework. A path can hold numeric parameters ("/api/history/{id}"),
 * handed to the action as integers.
 *
 * What no route matches: the fallback when one is set for that path (the pages of
 * the app), otherwise 404 with the code the frontend knows.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, action: array{0: class-string<Controller>, 1: string}}> */
    private array $routes = [];

    /** @var ?array{prefix: string, action: array{0: class-string<Controller>, 1: string}} */
    private ?array $fallback = null;

    public function __construct(private readonly Config $config)
    {
    }

    /** @param array{0: class-string<Controller>, 1: string} $action */
    public function get(string $path, array $action): void
    {
        $this->add('GET', $path, $action);
    }

    /** @param array{0: class-string<Controller>, 1: string} $action */
    public function post(string $path, array $action): void
    {
        $this->add('POST', $path, $action);
    }

    /** @param array{0: class-string<Controller>, 1: string} $action */
    public function delete(string $path, array $action): void
    {
        $this->add('DELETE', $path, $action);
    }

    /**
     * Every path that does not start with `$exceptPrefix` goes to this action (the
     * built frontend answers its own pages), whatever the method.
     *
     * @param array{0: class-string<Controller>, 1: string} $action
     */
    public function fallback(string $exceptPrefix, array $action): void
    {
        $this->fallback = ['prefix' => $exceptPrefix, 'action' => $action];
    }

    /** Runs the action of the first route that matches; every action ends the request. */
    public function dispatch(string $method, string $path): never
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['regex'], $path, $match) === 1) {
                $params = array_map('intval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
                $this->run($route['action'], $params);
            }
        }

        if ($this->fallback !== null && !str_starts_with($path, $this->fallback['prefix'])) {
            $this->run($this->fallback['action'], []);
        }

        Http::fail('unknown', 404);
    }

    /** @param array{0: class-string<Controller>, 1: string} $action */
    private function add(string $method, string $path, array $action): void
    {
        // "{id}" stands for digits only: "/api/history/abc" is simply not a route.
        $regex = preg_replace('#\\\{(\w+)\\\}#', '(?<$1>\d+)', preg_quote($path, '#'));
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'action' => $action];
    }

    /**
     * @param array{0: class-string<Controller>, 1: string} $action
     * @param array<string, int> $params
     */
    private function run(array $action, array $params): never
    {
        [$class, $name] = $action;
        (new $class($this->config))->{$name}(...$params);

        // An action that forgot to answer still ends the request, as an empty success.
        exit;
    }
}
