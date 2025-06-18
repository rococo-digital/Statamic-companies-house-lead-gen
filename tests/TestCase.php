<?php

namespace Rococo\ChLeadGen\Tests;

use Rococo\ChLeadGen\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
