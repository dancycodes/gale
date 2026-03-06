<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\Prefix;
use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Fixture controller with Prefix attribute for testing.
 */
#[Prefix('/api/v1')]
class PrefixedController
{
    #[Route('GET', name: 'api.users.index')]
    public function index(): void {}

    #[Route('POST', '/create', name: 'api.users.create')]
    public function create(): void {}
}
