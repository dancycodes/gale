<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\Attributes\WithTrashed;

/**
 * Fixture controller with WithTrashed attribute for testing.
 */
class WithTrashedController
{
    #[Route('GET', '/items/{id}')]
    #[WithTrashed]
    public function show(int $id): void {}

    #[Route('GET', '/items')]
    public function index(): void {}
}
