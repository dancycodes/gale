<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\DoNotDiscover;
use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Fixture controller with DoNotDiscover on specific methods.
 */
class DoNotDiscoverController
{
    #[Route('GET', '/visible')]
    public function visible(): void {}

    #[DoNotDiscover]
    #[Route('GET', '/hidden')]
    public function hidden(): void {}
}
