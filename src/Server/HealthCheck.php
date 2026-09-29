<?php

namespace RobertBoes\Patchbay\Server;

use Illuminate\Contracts\Config\Repository as Config;
use RobertBoes\Patchbay\Models\App;

class HealthCheck
{
    public function __construct(
        protected ServerApi $api,
        protected Heartbeat $heartbeat,
        protected Config $config,
    ) {
        //
    }

    public function status(): Health
    {
        if (! $this->api->isRunning()) {
            return Health::Down;
        }

        $beats = $this->heartbeat->live();

        if ($beats === []) {
            return Health::Degraded;
        }

        $servingNothing = collect($beats)->contains(fn(array $beat) => $beat['applications'] === 0);

        return $servingNothing && $this->hasActiveApplications()
            ? Health::Degraded
            : Health::Operational;
    }

    /**
     * Every active application, not only those a signed-in user may see:
     * the question is about the server, not the viewer.
     */
    protected function hasActiveApplications(): bool
    {
        $model = $this->config->get('patchbay.model', App::class);

        return $model::query()->withoutGlobalScopes()->where('active', true)->exists();
    }
}
