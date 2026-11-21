<?php

namespace Passorde\Licence;

/**
 * THE place where the licence service's public address lives: the one the daily refresh asks for the renewed key
 * (GET {REFRESH_URL}/licence/{licence_id}). PASSORDE_LICENCE_REFRESH_URL replaces it, for a test service; empty or
 * absent, this address is used, so an untouched install refreshes with no setting at all.
 *
 * If the service moves to its own domain at publication, change this one line and release a version.
 */
final class LicenceService
{
    public const REFRESH_URL = 'https://passorde-licensing.marinailsapps.workers.dev';

    /**
     * The address the grace, expired and updates-ended messages give for buying, renewing or extending a licence:
     * the Passorde page, whose connectors section lists the prices.
     */
    public const SITE_URL = 'https://apps.marinails.site/passorde';
}
