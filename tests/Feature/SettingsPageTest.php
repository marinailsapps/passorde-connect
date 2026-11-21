<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Http\Controllers\LicenceController;
use Passorde\Licence\LicenceManager;
use Passorde\PassordeServiceProvider;
use Passorde\Tests\Support\TestLicence;

it('shows the state, plan, company, expiry and last refresh', function () {
    app(LicenceManager::class)->install(TestLicence::signed());

    $this->get('/passorde/licence')
        ->assertOk()
        ->assertSee('Valide')
        ->assertSee('12m')
        ->assertSee('Exemple SARL')
        ->assertSee('2026-12-31')
        ->assertSee('Dernier rafraîchissement')
        ->assertSee('Passorde Connect')
        ->assertSee('Connecteurs couverts')
        ->assertDontSee('token-test')
        ->assertDontSee('<img', false);
});

it('shows the notices of the registered connectors', function () {
    app(ConnectorRegistry::class)->register('acme', 'Acme', 'Connecteur Acme : non affilié à Acme.');
    app(LicenceManager::class)->install(TestLicence::signed());

    $this->get('/passorde/licence')->assertOk()->assertSee('Connecteur Acme : non affilié à Acme.')->assertSee('Acme');
});

it('stays in French when the application locale is English', function () {
    app()->setLocale('en');

    $this->get('/passorde/licence')->assertOk()->assertSee('Installer une licence')->assertDontSee('passorde::');
});

it('links back to the root of the host application by default', function () {
    expect(config('passorde-connect.route.back_url'))->toBe('/');

    $this->get('/passorde/licence')
        ->assertOk()
        ->assertSee('<nav class="back"><a href="/">Retour</a></nav>', false);
});

it('links back to the configured URL, escaped', function () {
    config()->set('passorde-connect.route.back_url', '/admin/settings?tab="x"');

    $this->get('/passorde/licence')
        ->assertOk()
        ->assertSee('<a href="/admin/settings?tab=&quot;x&quot;">Retour</a>', false);
});

it('falls back to the root when the back URL is empty', function () {
    config()->set('passorde-connect.route.back_url', '');

    $this->get('/passorde/licence')->assertOk()->assertSee('<a href="/">Retour</a>', false);
});

it('shows that nothing is installed', function () {
    $this->get('/passorde/licence')->assertOk()->assertSee('Invalide')->assertSee('Aucune licence');
});

it('escapes licence values', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['company' => '<script>alert(1)</script>']));

    $this->get('/passorde/licence')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});

it('installs a pasted licence', function () {
    $raw = TestLicence::signed();

    $this->post('/passorde/licence', ['licence' => $raw])
        ->assertRedirect('/passorde/licence')
        ->assertSessionHas('passorde_ok');

    expect(file_get_contents($this->licencePath))->toBe($raw);
});

it('installs an uploaded licence', function () {
    $raw = TestLicence::signed();

    $this->post('/passorde/licence', ['licence_file' => UploadedFile::fake()->createWithContent('licence.key', $raw)])
        ->assertRedirect('/passorde/licence')
        ->assertSessionHas('passorde_ok');

    expect(file_get_contents($this->licencePath))->toBe($raw);
});

it('refuses an invalid licence and keeps the current one', function () {
    $good = TestLicence::signed();
    app(LicenceManager::class)->install($good);

    $this->post('/passorde/licence', ['licence' => TestLicence::signed(['kid' => '2099-01'])])
        ->assertRedirect('/passorde/licence')
        ->assertSessionHas('passorde_error');

    expect(file_get_contents($this->licencePath))->toBe($good);
});

it('asks for a licence when the form is empty', function () {
    $this->post('/passorde/licence', ['licence' => '  '])->assertSessionHas('passorde_error');
});

it('refuses an oversized licence', function () {
    $this->post('/passorde/licence', ['licence' => str_repeat('x', 65537)])->assertSessionHas('passorde_error');
});

it('refreshes from the button', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    Http::fake(['*' => Http::response(TestLicence::signed(['expires_at' => '2027-12-31']))]);

    $this->post('/passorde/licence/refresh')->assertRedirect('/passorde/licence')->assertSessionHas('passorde_ok');
    $this->get('/passorde/licence')->assertSee('2027-12-31')->assertDontSee('Jamais');
});

it('shows nothing to refresh as a notice, not an error', function () {
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => null, 'licence_token' => null]));
    Http::fake();

    $this->post('/passorde/licence/refresh')->assertSessionHas('passorde_ok', 'Rien à rafraîchir : licence à vie sans jeton de rafraîchissement.');
    Http::assertNothingSent();
});

it('shows a refresh failure', function () {
    app(LicenceManager::class)->install(TestLicence::signed());
    Http::fake(['*' => Http::response('', 500)]);

    $this->post('/passorde/licence/refresh')->assertSessionHas('passorde_error');
});

it('has no inline style, so that a style-src self policy holds', function () {
    app(LicenceManager::class)->install(TestLicence::signed());

    $html = $this->get('/passorde/licence')->assertOk()->getContent();
    $css = route('passorde.licence.css', ['v' => substr(md5_file(LicenceController::stylesheetPath()), 0, 12)]);

    expect($html)->not->toContain('<style')
        ->not->toMatch('/\sstyle\s*=/i')
        ->not->toContain('<script')
        ->toContain('<link rel="stylesheet" href="'.e($css).'">')
        ->and(file_get_contents(__DIR__.'/../../resources/views/licence.blade.php'))->not->toMatch('/<style|\sstyle\s*=/i');
});

it('serves the stylesheet from a package route behind the same middleware', function () {
    $response = $this->get('/passorde/licence.css')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/css; charset=utf-8')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toContain('.message.error')
        ->and(Route::getRoutes()->getByName('passorde.licence.css')->gatherMiddleware())->toBe(Route::getRoutes()->getByName('passorde.licence.show')->gatherMiddleware());
});

it('links the published stylesheet once the host has published it', function () {
    $published = public_path(LicenceController::PUBLISHED_STYLESHEET);
    File::ensureDirectoryExists(dirname($published));
    File::copy(LicenceController::stylesheetPath(), $published);

    try {
        $this->get('/passorde/licence')->assertOk()->assertSee(asset(LicenceController::PUBLISHED_STYLESHEET).'?v=', false);
    } finally {
        File::delete($published);
    }
});

it('publishes the stylesheet under the passorde-assets tag', function () {
    $paths = ServiceProvider::pathsToPublish(PassordeServiceProvider::class, 'passorde-assets');

    expect($paths)->toBe([LicenceController::stylesheetPath() => public_path('vendor/passorde/licence.css')])
        ->and(LicenceController::stylesheetPath())->toBeFile();
});

it('registers no package route when the routes are switched off', function () {
    config()->set('passorde-connect.route.enabled', false);
    app('router')->setRoutes(new RouteCollection);

    (new PassordeServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();

    expect(Route::has('passorde.licence.show'))->toBeFalse()
        ->and(Route::has('passorde.licence.css'))->toBeFalse()
        ->and(Route::getRoutes()->count())->toBe(0);
});

it('reads the route switch from the environment', function (?string $value, bool $enabled) {
    $value === null ? putenv('PASSORDE_ROUTE_ENABLED') : putenv('PASSORDE_ROUTE_ENABLED='.$value);

    try {
        expect((require __DIR__.'/../../config/passorde-connect.php')['route']['enabled'])->toBe($enabled);
    } finally {
        putenv('PASSORDE_ROUTE_ENABLED');
    }
})->with([
    'unset' => [null, true],
    'empty' => ['', true],
    'true' => ['true', true],
    'false' => ['false', false],
    'zero' => ['0', false],
    'one' => ['1', true],
]);
