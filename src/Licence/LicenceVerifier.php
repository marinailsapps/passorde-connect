<?php

namespace Passorde\Licence;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Passorde;
use SodiumException;
use stdClass;

final class LicenceVerifier
{
    /** The product id every licence of this package carries; each connector it covers is listed in connectors. */
    public const PRODUCT = 'passorde-connect';

    private const TIMEZONE = 'Europe/Paris';

    /** Tolerance on issued_at only, for a clock behind the signing service's: it does not lengthen any period. */
    private const SKEW_SECONDS = 86400;

    /** Calendar days in Europe/Paris after the paid period during which everything runs, with a warning. */
    public const GRACE_DAYS = 14;

    /** Required string fields, in addition to kid and signature which are checked before the signature. */
    private const REQUIRED_STRINGS = ['licence_id', 'product', 'plan', 'issued_at', 'version_max'];

    /** major.minor.patch, then an optional pre-release and build: only the first two count against version_max. */
    private const PACKAGE_VERSION = '/^(\d+)\.(\d+)\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/';

    /**
     * @param  array<string, string>  $publicKeys  Base64 public keys by key id.
     */
    public function __construct(
        private readonly array $publicKeys = PublicKeys::KEYS,
        private readonly string $packageVersion = Passorde::VERSION,
        private readonly string $releasedAt = Passorde::RELEASED_AT,
    ) {}

    /**
     * @param  string  $raw  A licence key (LicenceKey). A licence in JSON form is refused (legacy_format).
     * @param  string|null  $connector  The connector in use: the licence must list it. Null checks no connector.
     */
    public function verify(string $raw, ?DateTimeImmutable $now = null, ?string $connector = null): LicenceStatus
    {
        $now ??= CarbonImmutable::now();
        $fields = LicenceKey::decode($raw);

        if ($fields instanceof LicenceReason) {
            return LicenceStatus::invalid($fields);
        }

        return $this->checkSignature($fields) ?? $this->checkFields($fields, $now, $connector);
    }

    /**
     * Nothing in the file is trusted until this returns null.
     */
    private function checkSignature(stdClass $fields): ?LicenceStatus
    {
        foreach (['kid', 'signature'] as $name) {
            if (! property_exists($fields, $name)) {
                return LicenceStatus::invalid(LicenceReason::MissingField, ['field' => $name]);
            }
            if (! is_string($fields->{$name}) || $fields->{$name} === '') {
                return LicenceStatus::invalid(LicenceReason::InvalidField, ['field' => $name]);
            }
        }

        $publicKey = $this->publicKeys[$fields->kid] ?? null;

        if ($publicKey === null) {
            return LicenceStatus::invalid(LicenceReason::UnknownKid, ['kid' => $fields->kid]);
        }

        return $this->signatureMatches($fields, $publicKey)
            ? null
            : LicenceStatus::invalid(LicenceReason::BadSignature);
    }

    private function signatureMatches(stdClass $fields, string $publicKey): bool
    {
        $signature = base64_decode($fields->signature, true);
        $key = base64_decode($publicKey, true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, CanonicalJson::encode($fields), $key);
        } catch (SodiumException|JsonException) {
            return false;
        }
    }

    private function checkFields(stdClass $fields, DateTimeImmutable $now, ?string $connector): LicenceStatus
    {
        foreach ([...self::REQUIRED_STRINGS, 'connectors', 'expires_at'] as $name) {
            if (! property_exists($fields, $name)) {
                return LicenceStatus::invalid(LicenceReason::MissingField, ['field' => $name]);
            }
        }

        foreach (self::REQUIRED_STRINGS as $name) {
            if (! is_string($fields->{$name}) || $fields->{$name} === '') {
                return LicenceStatus::invalid(LicenceReason::InvalidField, ['field' => $name]);
            }
        }

        if ($fields->expires_at !== null && ! is_string($fields->expires_at)) {
            return LicenceStatus::invalid(LicenceReason::InvalidField, ['field' => 'expires_at']);
        }

        // Optional: absent or null means updates without a date limit (a subscription covers what it pays for).
        if (($fields->updates_until ?? null) !== null && ! is_string($fields->updates_until)) {
            return LicenceStatus::invalid(LicenceReason::InvalidField, ['field' => 'updates_until']);
        }

        if (! self::isConnectorList($fields->connectors)) {
            return LicenceStatus::invalid(LicenceReason::InvalidField, ['field' => 'connectors']);
        }

        $licence = Licence::fromVerified($fields);

        if ($licence->product !== self::PRODUCT) {
            return LicenceStatus::invalid(LicenceReason::WrongProduct, ['product' => $licence->product], $licence);
        }

        if ($connector !== null && ! $licence->covers($connector)) {
            return LicenceStatus::invalid(LicenceReason::ConnectorNotCovered, [
                'connector' => $connector,
                'connectors' => implode(', ', $licence->connectors),
                'notice' => '',
            ], $licence);
        }

        return $this->checkVersion($licence) ?? $this->checkUpdates($licence) ?? $this->checkDates($licence, $now);
    }

    /**
     * A non-empty list of distinct connector ids, each in the form ConnectorRegistry::ID_PATTERN.
     */
    private static function isConnectorList(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $id) {
            if (! is_string($id) || preg_match(ConnectorRegistry::ID_PATTERN, $id) !== 1) {
                return false;
            }
        }

        return count(array_unique($value)) === count($value);
    }

    /**
     * A pre-release counts as the version it leads to: 1.5.0-beta.1 needs a licence that covers 1.5, as 1.5.0 will.
     */
    private function checkVersion(Licence $licence): ?LicenceStatus
    {
        if (
            preg_match('/^(\d+)\.(x|\d+)\z/', $licence->versionMax, $max) !== 1
            || preg_match(self::PACKAGE_VERSION, $this->packageVersion, $package) !== 1
        ) {
            return LicenceStatus::invalid(LicenceReason::BadVersionMax, ['version_max' => $licence->versionMax], $licence);
        }

        $covered = $max[2] === 'x'
            ? (int) $package[1] <= (int) $max[1]
            : [(int) $package[1], (int) $package[2]] <= [(int) $max[1], (int) $max[2]];

        return $covered ? null : LicenceStatus::invalid(LicenceReason::VersionExceeded, [
            'version_max' => $licence->versionMax,
            'version' => $this->packageVersion,
        ], $licence);
    }

    /**
     * A licence with updates_until covers the versions released on or before that day (Passorde::RELEASED_AT), and
     * no later one: on an older version it keeps working, without end date for a lifetime licence. Calendar dates in
     * Europe/Paris, compared as days; the clock plays no part.
     */
    private function checkUpdates(Licence $licence): ?LicenceStatus
    {
        if ($licence->updatesUntil === null) {
            return null;
        }

        $until = $this->parseDate($licence->updatesUntil);

        if ($until === null) {
            return LicenceStatus::invalid(LicenceReason::MalformedDate, ['field' => 'updates_until'], $licence);
        }

        $released = $this->parseDate($this->releasedAt);

        if ($released === null) {
            // A release date this version cannot read: take no chance, as with an unreadable version.
            return LicenceStatus::invalid(LicenceReason::MalformedDate, ['field' => 'released_at'], $licence);
        }

        return $released <= $until ? null : LicenceStatus::invalid(LicenceReason::UpdatesEnded, [
            'date' => $licence->updatesUntil,
            'released' => $this->releasedAt,
            'version' => $this->packageVersion,
            'before' => $until->modify('+1 day')->format('Y-m-d'),
        ], $licence);
    }

    private function checkDates(Licence $licence, DateTimeImmutable $now): LicenceStatus
    {
        $issuedAt = $this->parseDate($licence->issuedAt);

        if ($issuedAt === null) {
            return LicenceStatus::invalid(LicenceReason::MalformedDate, ['field' => 'issued_at'], $licence);
        }

        if ($issuedAt > $this->addSeconds($now, self::SKEW_SECONDS)) {
            return LicenceStatus::invalid(LicenceReason::IssuedInFuture, ['date' => $licence->issuedAt], $licence);
        }

        if ($licence->expiresAt === null) {
            return new LicenceStatus(LicenceState::Valid, LicenceReason::Lifetime, licence: $licence);
        }

        $expiryDay = $this->parseDate($licence->expiresAt);

        if ($expiryDay === null) {
            return LicenceStatus::invalid(LicenceReason::MalformedDate, ['field' => 'expires_at'], $licence);
        }

        // expires_at is the last paid day. The paid period ends at 00:00 Europe/Paris the next day, when the warning
        // starts; synchronisation stops GRACE_DAYS calendar days later, at 00:00 Europe/Paris again. No margin is
        // added on either side (docs/licence-key.md, "Échéance et période de grâce").
        $validUntil = $expiryDay->modify('+1 day');
        $graceUntil = $expiryDay->modify('+'.(1 + self::GRACE_DAYS).' days');

        if ($now < $validUntil) {
            return new LicenceStatus(LicenceState::Valid, LicenceReason::Active, ['date' => $licence->expiresAt], $licence);
        }

        if ($now < $graceUntil) {
            // until: the exact end, as before; last_day: the last full day of grace, which the text shows.
            return new LicenceStatus(LicenceState::Grace, LicenceReason::InGrace, [
                'date' => $licence->expiresAt,
                'until' => $graceUntil->format('Y-m-d H:i'),
                'last_day' => LicenceStatus::lastFullDayBefore($graceUntil),
            ], $licence, $graceUntil);
        }

        return new LicenceStatus(LicenceState::Expired, LicenceReason::Expired, ['date' => $licence->expiresAt], $licence, $graceUntil);
    }

    /**
     * The start of that day in Europe/Paris, or null unless the value is a real YYYY-MM-DD date.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::TIMEZONE));

        return $date === false ? null : $date;
    }

    /**
     * Elapsed time, unaffected by daylight saving changes.
     */
    private function addSeconds(DateTimeImmutable $date, int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.($date->getTimestamp() + $seconds)))
            ->setTimezone(new DateTimeZone(self::TIMEZONE));
    }
}
