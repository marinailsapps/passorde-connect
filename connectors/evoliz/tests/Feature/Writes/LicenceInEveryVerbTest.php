<?php

use Generator as PhpGenerator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Passorde\Api\Contracts\EvolizApi;
use Passorde\Api\EvolizToken;
use Passorde\Drafts\ArticleDraft;
use Passorde\Drafts\ClientDraft;
use Passorde\Drafts\ClientPatch;
use Passorde\Drafts\ContactDraft;
use Passorde\Drafts\DeliveryDraft;
use Passorde\Drafts\DeliveryLineDraft;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceExpiredException;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceReason;
use Passorde\Licence\LicenceVerifier;
use Passorde\Tests\Support\FakeEvoliz;
use Passorde\Tests\Support\TestLicence;
use Passorde\Writes\AdjustStock;
use Passorde\Writes\CreateArticle;
use Passorde\Writes\CreateClient;
use Passorde\Writes\CreateDelivery;
use Passorde\Writes\UpdateClient;
use Passorde\Writes\UpsertContact;

/**
 * A transport a host might bind in place of the package's client: it checks no licence and records every call.
 */
final class HostOwnTransport implements EvolizApi
{
    /** @var list<string> */
    public array $calls = [];

    public function get(string $path, array $query = []): array
    {
        $this->calls[] = 'GET '.$path;

        return ['data' => []];
    }

    public function paginate(string $path, array $query = [], int $perPage = 100): PhpGenerator
    {
        $this->calls[] = 'PAGINATE '.$path;

        yield [];
    }

    public function send(string $method, string $path, array $options = []): Response
    {
        $this->calls[] = $method.' '.$path;

        throw new RuntimeException('The host transport was reached.');
    }
}

/**
 * @return array<string, Closure(): mixed>
 */
function everyWriteVerb(): array
{
    return [
        'CreateDelivery' => fn () => app(CreateDelivery::class)->run(new DeliveryDraft(601, 'CMD-1', '2026-06-20', 7, [new DeliveryLineDraft(3001, 1)])),
        'AdjustStock' => fn () => app(AdjustStock::class)->run(3001, -1),
        'CreateClient' => fn () => app(CreateClient::class)->run(new ClientDraft('Exemple SARL')),
        'UpdateClient' => fn () => app(UpdateClient::class)->run(601, new ClientPatch(name: 'Exemple SARL')),
        'UpsertContact' => fn () => app(UpsertContact::class)->run(601, new ContactDraft('Martin')),
        'CreateArticle' => fn () => app(CreateArticle::class)->run(new ArticleDraft('REF-1', 'Article', '10', '20')),
    ];
}

beforeEach(function () {
    config()->set('passorde-connect.evoliz.base_url', FakeEvoliz::BASE);
    config()->set('passorde-connect.evoliz.company_id', FakeEvoliz::COMPANY);
    config()->set('passorde-connect.evoliz.public_key', FakeEvoliz::PUBLIC_KEY);
    config()->set('passorde-connect.evoliz.secret_key', FakeEvoliz::SECRET);
    config()->set('passorde-connect.writes_enabled', true);
    config()->set('passorde-connect.request_log.driver', 'null');

    $this->transport = new HostOwnTransport;
    app()->instance(EvolizApi::class, $this->transport);
    FakeEvoliz::fake();
});

it('refuses every write verb without a key, even over a transport the host bound itself', function (string $verb) {
    expect(everyWriteVerb()[$verb])->toThrow(LicenceInvalidException::class)
        ->and($this->transport->calls)->toBe([]);

    Http::assertNothingSent();
})->with(array_keys(everyWriteVerb()));

it('refuses every write verb on an expired or tampered key, over that transport', function (string $verb) {
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));
    expect(everyWriteVerb()[$verb])->toThrow(LicenceExpiredException::class);

    // Installing refuses a tampered key, so it is written to the store directly, as a hand edit would.
    app(LicenceStore::class)->replace(TestLicence::tampered());
    expect(everyWriteVerb()[$verb])->toThrow(LicenceInvalidException::class)
        ->and($this->transport->calls)->toBe([]);

    Http::assertNothingSent();
})->with(array_keys(everyWriteVerb()));

it('reaches that transport once the key is valid, so it is the licence that stopped it', function () {
    app(LicenceManager::class)->install(TestLicence::signed());

    expect(everyWriteVerb()['CreateArticle'])->toThrow(RuntimeException::class, 'The host transport was reached.')
        ->and($this->transport->calls)->not->toBe([]);
});

it('hands out no Evoliz token without a valid key, cached or fresh', function () {
    expect(fn () => app(EvolizToken::class)->get())->toThrow(LicenceInvalidException::class)
        ->and(FakeEvoliz::logins())->toBe(0);

    app(LicenceManager::class)->install(TestLicence::signed());
    expect(app(EvolizToken::class)->get())->toBe(FakeEvoliz::TOKEN)
        ->and(FakeEvoliz::logins())->toBe(1);

    // The token is cached now: an expired key still gets nothing out of the cache.
    app(LicenceManager::class)->install(TestLicence::signed(['expires_at' => '2026-01-01']));
    expect(fn () => app(EvolizToken::class)->get())->toThrow(LicenceExpiredException::class)
        ->and(FakeEvoliz::logins())->toBe(1);
});

it('stops every call, without an error of its own, on a release newer than the end of a lifetime key\'s updates', function () {
    // The host sees an invalid licence with the reason updates_ended: Commandes then runs standalone.
    app()->instance(LicenceVerifier::class, TestLicence::verifier(releasedAt: '2026-11-14'));
    app()->forgetInstance(LicenceManager::class);
    app(LicenceStore::class)->replace(TestLicence::signed(['plan' => 'lifetime', 'expires_at' => null, 'updates_until' => '2026-11-13']));

    $status = app(LicenceManager::class)->status();
    expect($status->reasonCode)->toBe(LicenceReason::UpdatesEnded)
        ->and($status->isUsable())->toBeFalse()
        ->and(everyWriteVerb()['CreateDelivery'])->toThrow(LicenceInvalidException::class, 'publiées jusqu\'au 13 novembre 2026 inclus')
        ->and(fn () => app(EvolizToken::class)->get())->toThrow(LicenceInvalidException::class)
        ->and($this->transport->calls)->toBe([]);

    Http::assertNothingSent();
});
