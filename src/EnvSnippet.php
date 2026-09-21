<?php

namespace RobertBoes\Patchbay;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;

class EnvSnippet
{
    public function __construct(protected Config $config)
    {
        //
    }

    public function for(Model $app): string
    {
        $options = (array) $this->config->get('patchbay.options', []);

        return implode("\n", [
            'REVERB_APP_ID=' . $app->id,
            'REVERB_APP_KEY=' . $app->key,
            'REVERB_APP_SECRET=' . $app->secret,
            'REVERB_HOST=' . $this->quote((string) ($options['host'] ?? '127.0.0.1')),
            'REVERB_PORT=' . ($options['port'] ?? 443),
            'REVERB_SCHEME=' . ($options['scheme'] ?? 'https'),
            '',
            'VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"',
            'VITE_REVERB_HOST="${REVERB_HOST}"',
            'VITE_REVERB_PORT="${REVERB_PORT}"',
            'VITE_REVERB_SCHEME="${REVERB_SCHEME}"',
        ]);
    }

    protected function quote(string $value): string
    {
        return str_contains($value, ' ') ? '"' . $value . '"' : $value;
    }
}
