<?php

namespace RobertBoes\Patchbay\Server;

use Illuminate\Contracts\Config\Repository as Config;
use Laravel\Reverb\Certificate;

class ServerAddress
{
    public function __construct(protected Config $config)
    {
        //
    }

    public function url(): string
    {
        if ($url = $this->config->get('patchbay.server.url')) {
            return rtrim($url, '/');
        }

        $scheme = $this->isSecure() ? 'https' : 'http';

        return "{$scheme}://{$this->host()}:{$this->port()}";
    }

    /**
     * How clients reach the server, as the snippets hand it out. Not where
     * the panel reaches it: behind a proxy or in another container, the two
     * differ, and only this one means anything to a person reading it.
     */
    public function publicUrl(): string
    {
        $options = (array) $this->config->get('patchbay.options', []);
        $secure = ($options['scheme'] ?? 'https') === 'https';
        $host = (string) ($options['host'] ?? '127.0.0.1');
        $port = (int) ($options['port'] ?? 443);

        return ($secure ? 'https' : 'http') . "://{$host}"
            . ($port === ($secure ? 443 : 80) ? '' : ":{$port}");
    }

    public function isSecure(): bool
    {
        $tls = (array) $this->config->get($this->server('options.tls'), []);

        if (! empty($tls['local_cert'])) {
            return true;
        }

        $hostname = $this->hostname();

        return $hostname !== null && Certificate::exists($hostname);
    }

    protected function host(): string
    {
        // A certificate is valid only for the hostname it was issued for.
        if ($this->isSecure() && $hostname = $this->hostname()) {
            return $hostname;
        }

        $host = (string) $this->config->get($this->server('host'), '127.0.0.1');

        // 0.0.0.0 means every interface; it is not an address anything dials.
        return in_array($host, ['0.0.0.0', '::', ''], strict: true) ? '127.0.0.1' : $host;
    }

    protected function port(): int|string
    {
        return $this->config->get($this->server('port'), 8080);
    }

    protected function hostname(): ?string
    {
        $hostname = $this->config->get($this->server('hostname'));

        return $hostname === null || $hostname === '' ? null : (string) $hostname;
    }

    /** `reverb.default` chooses the live server block, which is not always "reverb". */
    protected function server(string $option): string
    {
        return "reverb.servers.{$this->config->get('reverb.default', 'reverb')}.{$option}";
    }
}
