<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the package TestCase to all test suites.
|
*/

pest()->extend(Dancycodes\Gale\Tests\TestCase::class)
    ->in('Feature', 'Unit');
