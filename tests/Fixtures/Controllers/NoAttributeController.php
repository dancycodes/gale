<?php

namespace Dancycodes\Gale\Tests\Fixtures\Controllers;

/**
 * Fixture controller with NO Route attributes for testing edge cases.
 */
class NoAttributeController
{
    public function index(): void {}

    public function store(): void {}

    protected function protectedMethod(): void {}

    private function privateMethod(): void {}
}
