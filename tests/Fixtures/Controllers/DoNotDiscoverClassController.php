<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;
use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Fixture controller with DoNotDiscover at class level.
 */
#[DoNotDiscover]
class DoNotDiscoverClassController
{
    #[Route('GET', '/should-not-appear')]
    public function index(): void {}

    #[Route('POST', '/also-hidden')]
    public function store(): void {}
}
