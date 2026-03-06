<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\Middleware;
use Dancycodes\Gale\Routing\Attributes\RateLimit;
use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Fixture controller with middleware attributes for testing.
 */
#[Middleware('auth')]
class MiddlewareController
{
    #[Route('GET', '/dashboard')]
    public function index(): void {}

    #[Route('POST', '/admin')]
    #[Middleware('admin', 'verified')]
    public function admin(): void {}

    #[Route('GET', '/api')]
    #[RateLimit(60, decayMinutes: 1)]
    public function api(): void {}
}
