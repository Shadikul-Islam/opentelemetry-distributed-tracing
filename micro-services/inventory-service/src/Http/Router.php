<?php

namespace Inventory\Http;

final class Router
{
    private $routes = array();

    public function add($method, $template, callable $handler)
    {
        $this->routes[] = array(
            'method' => strtoupper($method),
            'template' => $template,
            'regex' => $this->toRegex($template),
            'handler' => $handler,
        );
    }

    public function match($method, $path)
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            $params = array();
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }

            return array(
                'template' => $route['template'],
                'handler' => $route['handler'],
                'params' => $params,
            );
        }

        if ($pathMatched) {
            throw new MethodNotAllowed('Method ' . $method . ' not allowed for ' . $path);
        }

        return null;
    }

    private function toRegex($template)
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m) {
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $template
        );

        return '#^' . $pattern . '$#';
    }
}
