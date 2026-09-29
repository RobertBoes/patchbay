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

    /**
     * Whether the server answers at its public address: through whatever
     * proxy, DNS and certificate stand in front of it, the way a client
     * reaches it. Asked from here, so a server that cannot dial its own public
     * name (split DNS, hairpin NAT) reads as unreachable though browsers would
     * still get through; that is why this is not part of HealthCheck.
     */
    public function isPubliclyReachable(): bool
    {
        return (bool) $this->remember('public-up', function () {
            try {
                return $this->request()->get($this->address->publicUrl() . '/up')->successful();
            } catch (HttpClientException) {
                return false;
            }
        });
    }

    public function metrics(Application $application): AppMetrics
    {
        // Without asking for it the server returns names alone. Presence
        // channels count people rather than sockets, so they answer with
        // user_count and the rest with subscription_count; asking for both
        // costs nothing and lets each channel reply with the one it keeps.
        $channels = $this->get($application, "/apps/{$application->id()}/channels", [
            'info' => 'subscription_count,user_count',
        ]);

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
     * Who is on a presence channel. Other kinds keep no identity, so the
     * server answers with nothing for them.
     *
     * @return array<int, string>|null  Null when the server could not be asked.
     */
    public function channelUsers(Application $application, string $channel): ?array
    {
        $users = $this->get(
            $application,
            "/apps/{$application->id()}/channels/{$channel}/users",
        );

        if ($users === null) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn($user) => isset($user['id']) ? (string) $user['id'] : null,
            (array) ($users['users'] ?? []),
        )));
    }

    /**
     * Closes every connection a user holds. They are free to reconnect, so
     * this ends a session rather than barring anyone; deactivating the
     * application is what keeps them out.
     */
    public function terminateUser(Application $application, string $user): bool
    {
        $path = "/apps/{$application->id()}/users/{$user}/terminate_connections";

        // An empty body still has to be signed for, as the server checks the
        // digest it was given rather than whether one was needed.
        $body = '{}';

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
    /**
     * @param  array<string, string>  $query  Signed along with the rest, as the server verifies it.
     */
    protected function get(Application $application, string $path, array $query = []): ?array
    {
        $key = $application->id() . $path . ($query ? '?' . http_build_query($query) : '');

        return $this->remember($key, function () use ($application, $path, $query) {
            try {
                $response = $this->request()->get(
                    $this->url($path),
                    $this->sign($application, 'GET', $path, $query),
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
