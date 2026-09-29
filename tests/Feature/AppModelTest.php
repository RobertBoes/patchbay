<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Tests\TestCase;

class AppModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_cannot_be_mass_assigned(): void
    {
        $app = App::create([
            'name' => 'test',
            'key' => 'chosen-by-the-caller',
            'secret' => 'chosen-by-the-caller',
        ]);

        $this->assertNotSame('chosen-by-the-caller', $app->key);
        $this->assertNotSame('chosen-by-the-caller', $app->secret);
    }

    public function test_it_mints_credentials_on_create(): void
    {
        $app = App::create(['name' => 'test']);

        $this->assertSame(20, strlen($app->key));
        $this->assertSame(40, strlen($app->secret));
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $app = App::create(['name' => 'test']);

        $stored = $app->newQuery()->getConnection()
            ->table($app->getTable())
            ->where('id', $app->id)
            ->value('secret');

        $this->assertNotSame($app->secret, $stored, 'The secret must not be readable in the database.');
        // The 'encrypted' cast uses encryptString, so the payload holds a
        // raw string rather than a serialized value.
        $this->assertSame($app->secret, Crypt::decryptString($stored));
    }

    public function test_the_secret_is_hidden_from_array_conversion(): void
    {
        $app = App::create(['name' => 'test']);

        $this->assertArrayNotHasKey('secret', $app->toArray());
    }

    public function test_a_secret_cannot_be_rotated_by_mass_assignment(): void
    {
        $app = App::create(['name' => 'test']);
        $original = $app->secret;

        $app->update(['secret' => 'attempted-by-mass-assignment']);

        $this->assertSame($original, $app->fresh()->secret);
    }

    public function test_a_secret_is_rotated_by_assignment(): void
    {
        $app = App::create(['name' => 'test']);
        $original = $app->secret;

        $app->secret = App::generateSecret();
        $app->save();

        $this->assertNotSame($original, $app->fresh()->secret);
    }

    public function test_an_application_is_active_by_default(): void
    {
        $this->assertTrue(App::create(['name' => 'test'])->active);
    }
}
