<?php
/**
 * Minimal router. Routes: $router->get('students/{id}', [Ctrl::class,'show'])
 */
class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'path' => trim($path, '/'), 'handler' => $handler];
    }

    public function get(string $p, $h)    { $this->add('GET', $p, $h); }
    public function post(string $p, $h)   { $this->add('POST', $p, $h); }
    public function put(string $p, $h)    { $this->add('PUT', $p, $h); }
    public function delete(string $p, $h) { $this->add('DELETE', $p, $h); }

    public function dispatch(string $uri): void
    {
        $uri    = trim($uri, '/');
        $method = Request::method();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $pattern = '#^' . preg_replace('#\\\{([a-zA-Z_]+)\\\}#', '([^/]+)', preg_quote($route['path'], '#')) . '$#';
            if (preg_match($pattern, $uri, $m)) {
                array_shift($m);
                $h = $route['handler'];
                if (is_array($h)) {
                    $h = [new $h[0](), $h[1]];
                }
                call_user_func_array($h, $m);
                return;
            }
        }
        Response::error('Endpoint not found: ' . $method . ' /' . $uri, 404);
    }
}
