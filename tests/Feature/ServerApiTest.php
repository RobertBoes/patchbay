<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Reverb\Application;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Server\ServerApi;
use RobertBoes\Patchbay\Tests\TestCase;

class ServerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Results are cached in production; tests assert on each call.
        $app['config']->set('patchbay.server.cache_for', 0);
    }

    protected function api(): ServerApi
    {
        return $this->app->make(ServerApi::class);
    }

    protected function application(): Application
    {
        $app = App::create(['name' => 'test']);

        return $this->app->make(AppSource::class)->loadById($app->id);
    }

    public function test_triggering_sends_a_signed_event(): void
    {
        Http::fake(['*' => Http::response('{}', 200)]);
        $application = $this->application();

        $this->assertTrue($this->api()->trigger($application, 'orders', 'shipped', ['id' => 7]));

        Http::assertSent(function (Request $request) use ($application) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $signature = $query['auth_signature'];
            unset($query['auth_signature']);
            ksort($query);

            $path = "/apps/{$application->id()}/events";
            $canonical = urldecode(http_build_query($query));
            $expected = hash_hmac('sha256', "POST\n{$path}\n{$canonical}", $application->secret());

            return $request->method() === 'POST'
                && str_contains($request->url(), $path)
                && $query['body_md5'] === md5($request->body())
                && hash_equals($expected, $signature)
                && $request->data() === ['name' => 'shipped', 'channels' => ['orders'], 'data' => '{"id":7}'];
        });
    }

    public function test_a_refused_trigger_reports_failure(): void
    {
        Http::fake(['*' => Http::response('', 413)]);

        $this->assertFalse($this->api()->trigger($this->application(), 'orders', 'shipped', []));
    }

    public function test_it_reports_a_running_server(): void
    {
        Http::fake(['*/up' => Http::response('', 200)]);

        $this->assertTrue($this->api()->isRunning());
    }

    public function test_a_server_that_refuses_the_connection_is_not_running(): void
    {
        Http::fake(fn() => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->assertFalse($this->api()->isRunning());
    }

    public function test_it_reads_connections_and_channels(): void
    {
        Http::fake([
            '*/channels*' => Http::response(['channels' => ['orders' => [], 'chat' => []]]),
            '*/connections*' => Http::response(['connections' => 7]),
        ]);

        $metrics = $this->api()->metrics($this->application());

        $this->assertTrue($metrics->available);
        $this->assertSame(7, $metrics->connections);
        $this->assertSame(['orders', 'chat'], $metrics->channelNames());
        $this->assertSame(2, $metrics->channelCount());
    }

    public function test_an_unreachable_server_is_unavailable_rather_than_zero(): void
    {
        Http::fake(fn() => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $metrics = $this->api()->metrics($this->application());

        $this->assertFalse($metrics->available, 'Reporting zero connections would claim the server answered.');
        $this->assertSame(0, $metrics->connections);
    }

    public function test_a_failing_response_that_throws_is_unavailable(): void
    {
        Http::fake(fn() => throw new \Illuminate\Http\Client\RequestException(
            new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(404, [], 'No matching application'),
            ),
        ));

        $metrics = $this->api()->metrics($this->application());

        $this->assertFalse($metrics->available);
    }

    public function test_a_404_is_unavailable_rather_than_an_error(): void
    {
        Http::fake(['*' => Http::response('No matching application', 404)]);

        $this->assertFalse($this->api()->metrics($this->application())->available);
        $this->assertFalse($this->api()->isRunning());
    }

    public function test_it_signs_requests_with_the_application_secret(): void
    {
        Http::fake(['*' => Http::response(['channels' => []])]);

        $application = $this->application();
        $this->api()->metrics($application);

        Http::assertSent(function (Request $request) use ($application) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

            $params = ['auth_key' => $application->key(), 'auth_timestamp' => $query['auth_timestamp'], 'auth_version' => '1.0'];
            ksort($params);

            $canonical = implode('&', array_map(
                fn($key, $value) => "{$key}={$value}",
                array_keys($params),
                $params,
            ));

            $expected = hash_hmac(
                'sha256',
                implode("\n", ['GET', parse_url($request->url(), PHP_URL_PATH), $canonical]),
                $application->secret(),
            );

            return $query['auth_signature'] === $expected;
        });
    }

    public function test_it_dials_localhost_when_the_server_binds_every_interface(): void
    {
        config()->set('patchbay.server.url', null);
        config()->set('reverb.servers.reverb.host', '0.0.0.0');
        config()->set('reverb.servers.reverb.port', 9999);

        Http::fake(['*' => Http::response('', 200)]);

        $this->api()->isRunning();

        Http::assertSent(fn(Request $request) => $request->url() === 'http://127.0.0.1:9999/up');
    }

    public function test_an_explicit_url_wins(): void
    {
        config()->set('patchbay.server.url', 'https://sockets.example.test');

        Http::fake(['*' => Http::response('', 200)]);

        $this->api()->isRunning();

        Http::assertSent(fn(Request $request) => $request->url() === 'https://sockets.example.test/up');
    }
}
