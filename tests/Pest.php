<?php

use Passorde\Tests\EvolizTestCase;
use Passorde\Tests\TestCase;

// The core's tests run with no connector registered; the tests of the connector in connectors/evoliz run with its
// provider registered too, as a host application has them.
uses(TestCase::class)->in('Unit', 'Feature');
uses(EvolizTestCase::class)->in('../connectors/evoliz/tests/Unit', '../connectors/evoliz/tests/Feature');
