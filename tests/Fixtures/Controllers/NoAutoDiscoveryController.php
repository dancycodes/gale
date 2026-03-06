<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\NoAutoDiscovery;
use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Fixture controller with NoAutoDiscovery attribute for testing.
 * Only explicitly-attributed methods should be registered.
 */
#[NoAutoDiscovery]
class NoAutoDiscoveryController
{
    #[Route('GET', '/explicit')]
    public function explicitRoute(): void {}

    public function conventionalIndex(): void {}

    public function anotherMethod(): void {}
}
