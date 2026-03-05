<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\Group;
use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Fixture controller with Group attribute for testing.
 */
#[Group(prefix: '/admin', middleware: ['auth', 'admin'], as: 'admin.', domain: 'admin.example.com')]
class GroupController
{
    #[Route('GET', name: 'dashboard')]
    public function index(): void {}

    #[Route('GET', '/settings', name: 'settings')]
    public function settings(): void {}
}
