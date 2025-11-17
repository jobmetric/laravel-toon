<?php

namespace JobMetric\Toon\Tests;

use JobMetric\Toon\ToonServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ToonServiceProvider::class,
        ];
    }
}
