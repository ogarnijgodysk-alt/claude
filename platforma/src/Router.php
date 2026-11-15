<?php
declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;

final class Router
{
    /** @var array<int,array{0:string,1:string,2:array|callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, array|callable $handler): void
    {
        $re = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $re, $handler];
    }

    public function get(string $p, array|callable $h): void
    {
        $this->add('GET', $p, $h);
    }

    public function post(string $p, array|callable $h): void
    {
        $this->add('POST', $p, $h);
    }

    public function dispatch(Request $r): void
    {
        $allowedOther = false;
        foreach ($this->routes as [$m, $re, $h]) {
            if (!preg_match($re, $r->path, $mm)) {
                continue;
            }
            if ($m !== $r->method && !($m === 'GET' && $r->method === 'HEAD')) {
                $allowedOther = true;
                continue;
            }
            $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($r->isPost()) {
                Csrf::check($r);
            }
            if (is_array($h)) {
                [$cls, $meth] = $h;
                (new $cls())->$meth($r, ...array_values($params));
            } else {
                $h($r, ...array_values($params));
            }
            return;
        }
        Response::error($allowedOther ? 405 : 404);
    }
}
