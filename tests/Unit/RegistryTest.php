<?php

namespace RobertBoes\Patchbay\Tests\Unit;

use Laravel\Reverb\Application;
use PHPUnit\Framework\TestCase;
use RobertBoes\Patchbay\Registry;

class RegistryTest extends TestCase
{
    protected function application(string $id, string $key): Application
    {
        return new Application(
            id: $id,
            key: $key,
            secret: 'secret',
            pingInterval: 60,
            activityTimeout: 30,
            allowedOrigins: ['*'],
            maxMessageSize: 10_000,
        );
    }

    public function test_it_finds_applications_by_key_and_id(): void
    {
        $registry = new Registry();
        $registry->put($this->application('id-1', 'key-1'));

        $this->assertSame('id-1', $registry->findByKey('key-1')->id());
        $this->assertSame('key-1', $registry->findById('id-1')->key());
        $this->assertNull($registry->findByKey('nope'));
    }

    public function test_replacing_an_application_drops_its_previous_key(): void
    {
        $registry = new Registry();
        $registry->put($this->application('id-1', 'old-key'));
        $registry->put($this->application('id-1', 'new-key'));

        $this->assertNull($registry->findByKey('old-key'), 'A rotated key should stop resolving.');
        $this->assertSame('id-1', $registry->findByKey('new-key')->id());
        $this->assertSame(1, $registry->count());
    }

    public function test_forgetting_an_application_removes_both_indexes(): void
    {
        $registry = new Registry();
        $registry->put($this->application('id-1', 'key-1'));
        $registry->forgetById('id-1');

        $this->assertNull($registry->findById('id-1'));
        $this->assertNull($registry->findByKey('key-1'));
    }

    public function test_replace_marks_the_registry_complete(): void
    {
        $registry = new Registry();
        $this->assertFalse($registry->isComplete());

        $registry->replace([$this->application('id-1', 'key-1')]);

        $this->assertTrue($registry->isComplete());
        $this->assertSame(1, $registry->count());
    }

    public function test_flush_clears_everything_including_completeness(): void
    {
        $registry = new Registry();
        $registry->replace([$this->application('id-1', 'key-1')]);
        $registry->flush();

        $this->assertFalse($registry->isComplete());
        $this->assertSame(0, $registry->count());
        $this->assertNull($registry->findByKey('key-1'));
    }
}
