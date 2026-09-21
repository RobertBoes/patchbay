<?php

namespace RobertBoes\Patchbay\Server;

use Illuminate\Contracts\Config\Repository as Config;
use Laravel\Reverb\Certificate;

/**
 * Works out where the running Reverb server's HTTP API can be reached.
 *
 * Everything here is read from Reverb's own config, because Reverb is the one
 * that decided it. The scheme is not a free choice — Reverb serves TLS when TLS
 * options are set or a Valet or Herd certificate exists — so this asks the
 * questions Reverb asks rather than assuming plain HTTP.
 *
 * `REVERB_SERVER_HOST` is the interface the server listens on; `REVERB_HOST` is
 * the name it is reached by, and the name a certificate is looked up against.
 */
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
     * Whether Reverb will have configured TLS on its listener.
     */
    public function isSecure(): bool
    {
        $tls = (array) $this->config->get($this->server('options.tls'), []);

        if (! empty($tls['local_cert'])) {
            return true;
        }

        $hostname = $this->hostname();

        return $hostname !== null && Certificate::exists($hostname);
    }

    /**
     * A certificate is valid only for the hostname it was issued for, so
     * dialling the bind address instead would fail verification.
     */
    protected function host(): string
    {
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

    /**
     * A config key on the server Reverb is set to run, which is not always the
     * one literally named "reverb".
     */
    protected function server(string $option): string
    {
        return "reverb.servers.{$this->config->get('reverb.default', 'reverb')}.{$option}";
    }
}
