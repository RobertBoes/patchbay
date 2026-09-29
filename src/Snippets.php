<?php

namespace RobertBoes\Patchbay;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;

/**
 * What a consuming application pastes in to connect. Reverb speaks the Pusher
 * protocol, so anything with a Pusher client can use an application, not
 * only Laravel.
 */
class Snippets
{
    public function __construct(protected Config $config)
    {
        //
    }

    /** A Laravel application's .env. Echo reads the VITE_ values from it. */
    public function env(Model $app): string
    {
        return implode("\n", [
            'REVERB_APP_ID=' . $app->id,
            'REVERB_APP_KEY=' . $app->key,
            'REVERB_APP_SECRET=' . $app->secret,
            'REVERB_HOST=' . $this->quote($this->host()),
            'REVERB_PORT=' . $this->port(),
            'REVERB_SCHEME=' . $this->scheme(),
            '',
            'VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"',
            'VITE_REVERB_HOST="${REVERB_HOST}"',
            'VITE_REVERB_PORT="${REVERB_PORT}"',
            'VITE_REVERB_SCHEME="${REVERB_SCHEME}"',
        ]);
    }

    /** Listening from a browser with pusher-js. Holds no secret. */
    public function browser(Model $app): string
    {
        return <<<JS
            import Pusher from 'pusher-js';

            const pusher = new Pusher('{$app->key}', {
                wsHost: '{$this->host()}',
                wsPort: {$this->port()},
                wssPort: {$this->port()},
                forceTLS: {$this->forceTls()},
                enabledTransports: ['ws', 'wss'],
                cluster: '', // required by pusher-js, unused by Reverb
            });

            pusher.subscribe('my-channel').bind('my-event', (data) => {
                console.log(data);
            });
            JS;
    }

    /** Broadcasting from any backend with a Pusher server SDK; Node shown. */
    public function server(Model $app): string
    {
        return <<<JS
            import Pusher from 'pusher';

            const pusher = new Pusher({
                appId: '{$app->id}',
                key: '{$app->key}',
                secret: '{$app->secret}',
                host: '{$this->host()}',
                port: '{$this->port()}',
                useTLS: {$this->forceTls()},
            });

            await pusher.trigger('my-channel', 'my-event', { message: 'Hello' });
            JS;
    }

    protected function host(): string
    {
        return (string) ($this->options()['host'] ?? '127.0.0.1');
    }

    protected function port(): int
    {
        return (int) ($this->options()['port'] ?? 443);
    }

    protected function scheme(): string
    {
        return (string) ($this->options()['scheme'] ?? 'https');
    }

    protected function forceTls(): string
    {
        return $this->scheme() === 'https' ? 'true' : 'false';
    }

    /** @return array<string, mixed> */
    protected function options(): array
    {
        return (array) $this->config->get('patchbay.options', []);
    }

    protected function quote(string $value): string
    {
        return str_contains($value, ' ') ? '"' . $value . '"' : $value;
    }
}
