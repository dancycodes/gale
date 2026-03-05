<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\Route;
use Dancycodes\Gale\Routing\Attributes\Where;

/**
 * Fixture controller with Where constraints for testing.
 */
class WhereConstraintController
{
    #[Route('GET', '/users/{id}')]
    #[Where('id', '[0-9]+')]
    public function show(int $id): void {}

    #[Route('GET', '/users/{slug}')]
    #[Where('slug', '[a-z\-]+')]
    public function bySlug(string $slug): void {}
}
