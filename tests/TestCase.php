<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Fortify\Features;
use Laravel\Passport\Passport;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePassportKeysExist();
    }

    /**
     * Point Passport at per-process temp signing keys, generating them once.
     *
     * Resolving the Passport guard builds the OAuth resource server, which
     * loads the keys — so even an unauthenticated request to a route using
     * the `api` guard needs them. Environments like CI have no storage keys;
     * without this, such requests 500 instead of 401.
     */
    protected function ensurePassportKeysExist(): void
    {
        $keyDir = sys_get_temp_dir().'/kanbrio-passport-keys-'.getmypid();

        Passport::loadKeysFrom($keyDir);

        if (! file_exists($keyDir.'/oauth-private.key')) {
            File::ensureDirectoryExists($keyDir);
            Artisan::call('passport:keys');
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
