<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Basic fixture controller with Route attributes for testing.
 */
class BasicController
{
    #[Route('GET', '/users', name: 'users.index')]
    public function index(): void {}

    #[Route('POST', '/users', name: 'users.store')]
    public function store(): void {}

    #[Route(['GET', 'POST'], '/users/search', name: 'users.search')]
    public function search(): void {}
}
