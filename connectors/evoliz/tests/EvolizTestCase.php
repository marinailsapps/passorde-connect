<?php

namespace Passorde\Tests;

use Passorde\Evoliz\EvolizServiceProvider;
use Passorde\PassordeServiceProvider;

/**
 * The core's test case with the Evoliz connector registered, as a host has it.
 */
abstract class EvolizTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PassordeServiceProvider::class, EvolizServiceProvider::class];
    }
}
