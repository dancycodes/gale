<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Fixture controller with fullUri overrides for testing.
 */
class FullUriController
{
    #[Route('GET', fullUri: '/api/v2/users')]
    public function list(): void {}

    #[Route('GET', '/normal-uri')]
    public function normal(): void {}
}
