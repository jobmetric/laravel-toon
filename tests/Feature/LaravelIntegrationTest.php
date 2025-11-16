<?php

namespace JobMetric\Toon\Tests\Feature;

use JobMetric\Toon\Contracts\ToonManagerInterface;
use JobMetric\Toon\Facades\Toon;
use JobMetric\Toon\ToonServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Class LaravelIntegrationTest
 *
 * Ensures that the ToonServiceProvider is correctly registered in a
 * Laravel application context, and that the Facade and helper functions
 * can be used to encode and decode TOON data.
 */
class LaravelIntegrationTest extends TestCase
{
    /**
     * Get package service providers for the test environment.
     *
     * @param mixed $app The application instance.
     *
     * @return array<int,string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ToonServiceProvider::class,
        ];
    }

    /**
     * Get package aliases for the test environment.
     *
     * @param mixed $app The application instance.
     *
     * @return array<string,string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Toon' => Toon::class,
        ];
    }

    /**
     * Test that the ToonManagerInterface is bound in the container.
     *
     * @return void
     */
    public function testToonManagerIsResolvableFromContainer(): void
    {
        $manager = $this->app->make(ToonManagerInterface::class);

        $this->assertInstanceOf(ToonManagerInterface::class, $manager);
    }

    /**
     * Test that the Facade Toon::encode and Toon::decode work end to end.
     *
     * @return void
     */
    public function testFacadeEncodeDecode(): void
    {
        $data = [
            'id' => 1,
            'name' => 'Alice',
        ];

        $toon = \JobMetric\Toon\Facades\Toon::encode($data);

        $decoded = \JobMetric\Toon\Facades\Toon::decode($toon);

        $this->assertSame($data, $decoded);
    }

    /**
     * Test that the global helpers toon_encode and toon_decode work end to end.
     *
     * @return void
     */
    public function testHelperEncodeDecode(): void
    {
        $data = [
            'numbers' => [1, 2, 3],
        ];

        $toon = toon_encode($data);

        $decoded = toon_decode($toon);

        $this->assertSame($data, $decoded);
    }
}
