<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $post,
        public array $server,
        public array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)parse_url($uri, PHP_URL_PATH);
        $base = rtrim((string)\App\Config::get('base_path', ''), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim(rawurldecode($path), '/');
        return new self(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), $path, $_GET, $_POST, $_SERVER, $_FILES);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function input(string $k, mixed $d = null): mixed
    {
        return $this->post[$k] ?? $this->query[$k] ?? $d;
    }

    public function str(string $k, string $d = ''): string
    {
        $v = $this->post[$k] ?? $this->query[$k] ?? $d;
        return is_string($v) ? trim($v) : $d;
    }

    public function int(string $k, int $d = 0): int
    {
        return (int)($this->post[$k] ?? $this->query[$k] ?? $d);
    }

    public function arr(string $k): array
    {
        $v = $this->post[$k] ?? $this->query[$k] ?? [];
        return is_array($v) ? $v : [];
    }

    public function json(): array
    {
        static $cache = null;
        if ($cache === null) {
            $raw = file_get_contents('php://input');
            $j = $raw ? json_decode($raw, true) : null;
            $cache = is_array($j) ? $j : [];
        }
        return $cache;
    }

    public function ip(): string
    {
        $ip = (string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (\App\Config::get('trust_proxy', false) && !empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', (string)$this->server['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function isHttps(): bool
    {
        $f = \App\Config::get('force_https');
        if ($f !== null) {
            return (bool)$f;
        }
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || (\App\Config::get('trust_proxy', false) && ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public function wantsJson(): bool
    {
        return str_contains((string)($this->server['HTTP_ACCEPT'] ?? ''), 'application/json')
            || ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    }
}
