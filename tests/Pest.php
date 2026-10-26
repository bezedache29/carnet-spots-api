<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Reference Haversine distance in km, computed independently in PHP with
 * the exact same formula the `Point::scopeNearby()` SQL runs, so tests
 * don't rely on hand-rounded approximations.
 *
 * Declared globally here (rather than in a Feature test file) because
 * Pest loads every test file, and a second declaration anywhere else
 * would be a fatal "cannot redeclare" error.
 */
function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

    // Clamp to [0, 1]: floating-point rounding can push the argument
    // fractionally outside asin()'s domain (e.g. 1.0000000000002).
    $a = max(0.0, min(1.0, $a));

    return 2 * 6371 * asin(sqrt($a));
}

/**
 * The body a file download actually sends, Range included (BinaryFileResponse writes it straight to the output).
 */
function sentContent(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return ob_get_clean();
}
