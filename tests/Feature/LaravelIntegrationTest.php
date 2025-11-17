<?php

namespace JobMetric\Toon\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use JobMetric\Toon\Exceptions\ToonDecodeException;
use JobMetric\Toon\Exceptions\ToonEncodeException;
use JobMetric\Toon\Facades\Toon;
use JobMetric\Toon\Tests\TestCase;
use JobMetric\Toon\ToonManager;
use JobMetric\Toon\ToonServiceProvider;

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
     * Test that the ToonManager is bound in the container.
     *
     * @return void
     * @throws BindingResolutionException
     */
    public function testToonManagerIsResolvableFromContainer(): void
    {
        $manager = $this->app->make(ToonManager::class);

        $this->assertInstanceOf(ToonManager::class, $manager);
    }

    /**
     * Test that the Facade Toon::encode and Toon::decode work end to end.
     *
     * @return void
     * @throws ToonDecodeException
     * @throws ToonEncodeException
     */
    public function testFacadeEncodeDecode(): void
    {
        $data = [
            'id'   => 1,
            'name' => 'Alice',
        ];

        $toon = Toon::encode($data);

        $decoded = Toon::decode($toon);

        $this->assertSame($data, $decoded);
    }

    /**
     * Test that the global helpers toon_encode and toon_decode work end to end.
     *
     * @return void
     * @throws ToonDecodeException
     * @throws ToonEncodeException
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

    /**
     * Ensure container binding is a singleton and resolvable multiple times.
     *
     * @return void
     * @throws BindingResolutionException
     */
    public function testContainerBindingIsSingleton(): void
    {
        $a = $this->app->make('Toon');
        $b = $this->app->make('Toon');

        $this->assertSame($a, $b);
    }

    /**
     * Verify that configuration affects encoding strategy (tabular threshold) via Facade.
     *
     * When min_rows_tabular is raised above the row count, encoder should not use tabular form.
     *
     * @return void
     * @throws ToonDecodeException
     * @throws ToonEncodeException
     */
    public function testConfigAffectsTabularThresholdWithFacade(): void
    {
        config()->set('laravel-toon.min_rows_tabular', 3);

        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $encoded = Toon::encode($rows);

        $this->assertStringStartsWith("[2]:\n", $encoded);
        $this->assertStringNotContainsString('{id,name}', $encoded);

        $this->assertSame($rows, Toon::decode($encoded));
    }

    /**
     * Verify that custom delimiter is respected by helpers for primitive arrays.
     *
     * @return void
     * @throws ToonDecodeException
     * @throws ToonEncodeException
     */
    public function testCustomDelimiterWithHelpers(): void
    {
        config()->set('laravel-toon.delimiter', '|');

        $data = ['numbers' => [1, 2, 3]];

        $encoded = toon_encode($data);

        $this->assertStringContainsString('[3|]: 1|2|3', $encoded);
        $this->assertSame($data, toon_decode($encoded));
    }

    /**
     * Ensure helper throws on invalid TOON when configuration demands exceptions.
     *
     * @return void
     */
    public function testHelperDecodeThrowsOnInvalidWhenConfigured(): void
    {
        config()->set('laravel-toon.throw_on_decode_error', true);

        $this->expectException(ToonDecodeException::class);

        toon_decode("id:\n   name: Alice\n");
    }

    /**
     * Ensure helper returns null on invalid TOON when exceptions are disabled.
     *
     * @return void
     * @throws ToonDecodeException
     */
    public function testHelperDecodeReturnsNullWhenErrorsSuppressed(): void
    {
        config()->set('laravel-toon.throw_on_decode_error', false);

        $result = toon_decode("id:\n   name: Alice\n");

        $this->assertNull($result);
    }

    /**
     * Resolve via container alias string if available and ensure it implements the contract.
     *
     * @return void
     * @throws BindingResolutionException
     */
    public function testResolveAliasIfAvailable(): void
    {
        $resolved = $this->app->make('Toon');

        $this->assertInstanceOf(ToonManager::class, $resolved);
    }

    /**
     * Round trip a more complex nested structure via the Facade.
     *
     * @return void
     * @throws ToonDecodeException
     * @throws ToonEncodeException
     */
    public function testFacadeRoundTripComplexStructure(): void
    {
        $data = [
            'id'    => 42,
            'ok'    => true,
            'tags'  => ['x', 'y', 'z'],
            'users' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            'meta'  => [
                'empty' => [],
                'none'  => null,
            ],
        ];

        $encoded = Toon::encode($data);
        $decoded = Toon::decode($encoded);

        $this->assertSame($data, $decoded);
    }
}

