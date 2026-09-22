<?php

namespace RobertBoes\Patchbay\Server;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Factory as Http;
use Laravel\Reverb\Application;

class ServerApi
{
    protected int $timeout;

    protected bool $verify;

    protected int $cacheFor;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Http $http,
        protected Cache $cache,
        protected ServerAddress $address,
        array $config = [],
    ) {
        $this->timeout = (int) ($config['timeout'] ?? 2);
        $this->verify = (bool) ($config['verify'] ?? true);
        $this->cacheFor = (int) ($config['cache_for'] ?? 5);
    }

    public function isRunning(): bool
    {
        return (bool) $this->remember('up', function () {
            // HttpClientException, not ConnectionException: a client configured
            // to throw on 4xx raises a RequestException instead, and an
            // unreachable server must never take the page down with it.
            try {
                return $this->request()->get($this->url('/up'))->successful();
            } catch (HttpClientException) {
                return false;
            }
        });
    }

    public function metrics(Application $application): AppMetrics
    {
        $channels = $this->get($application, "/apps/{$application->id()}/channels");

        if ($channels === null) {
            return AppMetrics::unavailable();
        }

        $connections = $this->get($application, "/apps/{$application->id()}/connections");

        return new AppMetrics(
            available: true,
            connections: (int) ($connections['connections'] ?? 0),
            channels: (array) ($channels['channels'] ?? []),
        );
    }

    /**
     * Broadcasts an event through the server's HTTP API, the way any backend
     * with a Pusher SDK would.
     *
     * @param  array<mixed>  $data
     */
    public function trigger(Application $application, string $channel, string $event, array $data): bool
    {
        $path = "/apps/{$application->id()}/events";

        $body = (string) json_encode([
            'name' => $event,
            'channels' => [$channel],
            'data' => json_encode($data),
        ]);

        try {
            $response = $this->request()
                ->withBody($body, 'application/json')
                ->post($this->url($path) . '?' . http_build_query(
                    $this->sign($application, 'POST', $path, ['body_md5' => md5($body)]),
                ));
        } catch (HttpClientException) {
            return false;
        }

        return $response->successful();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function get(Application $application, string $path): ?array
    {
        return $this->remember($application->id() . $path, function () use ($application, $path) {
            try {
                $response = $this->request()->get(
                    $this->url($path),
                    $this->sign($application, 'GET', $path),
                );
            } catch (HttpClientException) {
                return null;
            }

            return $response->successful() ? $response->json() : null;
        });
    }

    /**
     * @param  array<string, string>  $extra  Signed along with the rest, as a POST's body_md5 must be.
     * @return array<string, string>
     */
    protected function sign(Application $application, string $method, string $path, array $extra = []): array
    {
        $params = [
            'auth_key' => $application->key(),
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            ...$extra,
        ];

        ksort($params);

        $canonical = implode('&', array_map(
            fn(string $key, string $value) => "{$key}={$value}",
            array_keys($params),
            $params,
        ));

        $params['auth_signature'] = hash_hmac(
            'sha256',
            implode("\n", [$method, $path, $canonical]),
            $application->secret(),
        );

        return $params;
    }

    protected function request()
    {
        return $this->http
            ->timeout($this->timeout)
            ->connectTimeout($this->timeout)
            ->withOptions(['verify' => $this->verify]);
    }

    protected function url(string $path): string
    {
        return $this->address->url() . $path;
    }

    protected function remember(string $key, callable $callback): mixed
    {
        if ($this->cacheFor < 1) {
            return $callback();
        }

        return $this->cache->remember('patchbay:server:' . md5($key), $this->cacheFor, $callback);
    }
}
