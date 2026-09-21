<?php

namespace RobertBoes\Patchbay\Tests\Unit;

use RobertBoes\Patchbay\Server\ServerAddress;
use RobertBoes\Patchbay\Tests\TestCase;

class ServerAddressTest extends TestCase
{
    protected function address(): ServerAddress
    {
        return $this->app->make(ServerAddress::class);
    }

    protected function certificateFor(string $hostname): void
    {
        // Reverb looks for <host>.crt and <host>.key beside each other in the
        // Valet or Herd certificate directory.
        $path = \Laravel\Reverb\Certificate::valetPath();

        @mkdir($path, 0755, recursive: true);
        touch($path . $hostname . '.crt');
        touch($path . $hostname . '.key');

        $this->beforeApplicationDestroyed(function () use ($path, $hostname) {
            @unlink($path . $hostname . '.crt');
            @unlink($path . $hostname . '.key');
        });
    }

    public function test_it_defaults_to_the_reverb_bind_address_over_plain_http(): void
    {
        config()->set('reverb.servers.reverb.host', '127.0.0.1');
        config()->set('reverb.servers.reverb.port', 8080);

        $this->assertSame('http://127.0.0.1:8080', $this->address()->url());
        $this->assertFalse($this->address()->isSecure());
    }

    public function test_it_dials_localhost_when_the_server_binds_every_interface(): void
    {
        config()->set('reverb.servers.reverb.host', '0.0.0.0');

        $this->assertStringStartsWith('http://127.0.0.1:', $this->address()->url());
    }

    /**
     * The case that bites in local development: setting REVERB_HOST to a name
     * with a Valet or Herd certificate makes Reverb serve TLS, so anything
     * still dialling plain HTTP on the bind address silently fails.
     */
    public function test_a_certificate_for_the_hostname_makes_the_address_secure(): void
    {
        $this->certificateFor('patchbay-test.test');

        config()->set('reverb.servers.reverb.hostname', 'patchbay-test.test');
        config()->set('reverb.servers.reverb.host', '127.0.0.1');
        config()->set('reverb.servers.reverb.port', 8080);

        $this->assertTrue($this->address()->isSecure());
        $this->assertSame(
            'https://patchbay-test.test:8080',
            $this->address()->url(),
            'A certificate is only valid for its hostname, so the bind address cannot be dialled.',
        );
    }

    public function test_a_hostname_without_a_certificate_stays_plain(): void
    {
        config()->set('reverb.servers.reverb.hostname', 'no-cert-here.test');
        config()->set('reverb.servers.reverb.host', '127.0.0.1');
        config()->set('reverb.servers.reverb.port', 8080);

        $this->assertFalse($this->address()->isSecure());
        $this->assertSame('http://127.0.0.1:8080', $this->address()->url());
    }

    public function test_explicit_tls_options_make_the_address_secure(): void
    {
        config()->set('reverb.servers.reverb.options.tls', ['local_cert' => '/etc/ssl/cert.pem']);
        config()->set('reverb.servers.reverb.host', 'sockets.internal');
        config()->set('reverb.servers.reverb.port', 8080);

        $this->assertTrue($this->address()->isSecure());
        $this->assertSame('https://sockets.internal:8080', $this->address()->url());
    }

    public function test_an_explicit_url_overrides_everything(): void
    {
        $this->certificateFor('patchbay-test.test');
        config()->set('reverb.servers.reverb.hostname', 'patchbay-test.test');
        config()->set('patchbay.server.url', 'http://reverb:8080/');

        $this->assertSame('http://reverb:8080', $this->address()->url());
    }

    public function test_it_reads_the_port_the_server_listens_on(): void
    {
        config()->set('reverb.servers.reverb.port', 9000);

        $this->assertStringEndsWith(':9000', $this->address()->url());
    }

    /**
     * `reverb.default` chooses which server block is live, so the address has
     * to follow it rather than assume the one named "reverb".
     */
    public function test_it_follows_the_configured_default_server(): void
    {
        config()->set('reverb.default', 'other');
        config()->set('reverb.servers.other', [
            'host' => 'sockets.internal',
            'port' => 9123,
            'hostname' => null,
            'options' => ['tls' => []],
        ]);

        $this->assertSame('http://sockets.internal:9123', $this->address()->url());
    }
}
