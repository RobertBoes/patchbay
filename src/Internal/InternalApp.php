<?php

namespace RobertBoes\Patchbay\Internal;

use Illuminate\Contracts\Config\Repository as Config;
use Laravel\Reverb\Application;
use RobertBoes\Patchbay\ApplicationFactory;

/**
 * The application the panel itself connects to, so the server can push what
 * is happening rather than wait to be asked.
 *
 * It is not one of yours. It has no row, so it cannot be listed, edited,
 * counted against a quota or deleted by accident, and it never appears in
 * traffic figures - the point is to report on your applications, not to
 * become one of the things reported on.
 *
 * Its credentials come from the application key rather than storage, so every
 * process that has the key agrees on them without coordinating, and rotating
 * the key rotates these too.
 */
class InternalApp
{
    public const ID = 'patchbay-internal';

    /** The one channel it carries, private so that subscribing must be signed. */
    public const CHANNEL = 'private-patchbay';

    public function __construct(
        protected Config $config,
        protected ApplicationFactory $factory,
    ) {
        //
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('patchbay.internal.enabled', true)
            && $this->appKey() !== '';
    }

    public function key(): string
    {
        return 'pbi-' . substr($this->derive('key'), 0, 28);
    }

    public function secret(): string
    {
        return $this->derive('secret');
    }

    public function is(Application|string|null $application): bool
    {
        $id = $application instanceof Application ? $application->id() : $application;

        return $id === self::ID;
    }

    public function application(): Application
    {
        return $this->factory->make([
            'id' => self::ID,
            'key' => $this->key(),
            'secret' => $this->secret(),
            // The panel is the only client, and it is served from wherever the
            // dashboard is. Restricting origins here would break every install
            // that reaches the dashboard by a name this server cannot know.
            'allowed_origins' => ['*'],
            // Nothing is broadcast between clients on this application; the
            // server is the only thing that publishes to it.
            'accept_client_events_from' => 'none',
        ]);
    }

    protected function derive(string $purpose): string
    {
        return hash_hmac('sha256', 'patchbay-internal:' . $purpose, $this->appKey());
    }

    protected function appKey(): string
    {
        $key = (string) $this->config->get('app.key', '');

        // Laravel stores the key base64 encoded; either form derives the same
        // credentials as long as every process agrees, and they all read this.
        return $key;
    }
}
