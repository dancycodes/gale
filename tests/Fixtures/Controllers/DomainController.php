<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

use Dancycodes\Gale\Routing\Attributes\Route;

/**
 * Fixture controller with domain constraints for testing.
 */
#[Route(domain: 'api.example.com')]
class DomainController
{
    #[Route('GET', '/status')]
    public function status(): void {}

    #[Route('GET', '/health', domain: 'health.example.com')]
    public function health(): void {}
}
