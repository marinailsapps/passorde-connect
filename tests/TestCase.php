<?php

namespace Passorde\Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Passorde\Licence\LicenceVerifier;
use Passorde\PassordeServiceProvider;
use Passorde\Tests\Support\TestLicence;

abstract class TestCase extends Orchestra
{
    protected string $licenceDirectory;

    protected string $licencePath;

    /** False boots the application as a host with the component switched off (passorde-connect.enabled). */
    protected bool $componentEnabled = true;

    protected function setUp(): void
    {
        $this->licenceDirectory = sys_get_temp_dir().'/passorde-tests-'.bin2hex(random_bytes(6));
        $this->licencePath = $this->licenceDirectory.'/licence.key';

        parent::setUp();

        Http::preventStrayRequests();
        $this->app->instance(LicenceVerifier::class, TestLicence::verifier());
        Carbon::setTestNow(TestLicence::NOW);
        CarbonImmutable::setTestNow(TestLicence::NOW);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->licenceDirectory);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [PassordeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        // Most tests read the key back from the file; the database store has its own tests.
        $app['config']->set('passorde-connect.licence.store', 'file');
        $app['config']->set('passorde-connect.licence.path', $this->licencePath);
        $app['config']->set('passorde-connect.licence.refresh_url', 'https://licences.test');
        $app['config']->set('passorde-connect.route.middleware', ['web']);
        $app['config']->set('passorde-connect.enabled', $this->componentEnabled);
    }

    /**
     * The application again, booted as a host that has the component switched off.
     */
    protected function switchComponentOff(): void
    {
        $this->componentEnabled = false;
        // The commands of the first boot are kept in a static list of the console application: forget them first.
        Application::forgetBootstrappers();
        $this->refreshApplication();
    }
}
