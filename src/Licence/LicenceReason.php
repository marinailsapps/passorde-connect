<?php

namespace Passorde\Licence;

/**
 * Each value is an English key under `reason` in lang/fr/licence.php.
 */
enum LicenceReason: string
{
    case NotInstalled = 'not_installed';
    case MalformedKey = 'malformed_key';
    case LegacyFormat = 'legacy_format';
    case UnsupportedKeyVersion = 'unsupported_key_version';
    case StoreUnavailable = 'store_unavailable';
    case MissingField = 'missing_field';
    case InvalidField = 'invalid_field';
    case UnknownKid = 'unknown_kid';
    case BadSignature = 'bad_signature';
    case WrongProduct = 'wrong_product';
    case ConnectorNotCovered = 'connector_not_covered';
    case BadVersionMax = 'bad_version_max';
    case VersionExceeded = 'version_exceeded';
    case UpdatesEnded = 'updates_ended';
    case MalformedDate = 'malformed_date';
    case IssuedInFuture = 'issued_in_future';
    case Active = 'active';
    case Lifetime = 'lifetime';
    case InGrace = 'in_grace';
    case Expired = 'expired';
}
