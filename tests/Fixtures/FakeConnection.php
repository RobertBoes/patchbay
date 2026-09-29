<?php

namespace RobertBoes\Patchbay\Tests\Fixtures;

use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\Connection;
use Ratchet\RFC6455\Messaging\Frame;

/**
 * A connection belonging to an application and doing nothing else, for the
 * events that carry one. Mockery cannot generate this contract: `control()`
 * declares a string parameter whose default is an integer constant, which
 * PHP rejects once written out.
 */
class FakeConnection extends Connection
{
    public function __construct(protected Application $application)
    {
        //
    }

    public function app(): Application
    {
        return $this->application;
    }

    public function identifier(): string
    {
        return 'fake';
    }

    public function id(): string
    {
        return 'fake';
    }

    public function send(string $message): void
    {
        //
    }

    public function control(string $type = Frame::OP_PING): void
    {
        //
    }

    public function terminate(): void
    {
        //
    }
}
