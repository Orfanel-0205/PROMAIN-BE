<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * No test may reach a real outside service.
     *
     * Tests read the developer's .env, which can hold the live Semaphore,
     * Gemini and Expo keys. Nothing stopped a test that published an event
     * from sending a real SMS blast: on 21 and 24 Oct 2026 full runs sent
     * about 200 real texts to the made-up numbers in the test fixtures.
     * Every HTTP call a test does not fake now fails instead of leaving the
     * machine, and phpunit.xml replaces the keys with dummies.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
