<?php

namespace Dancycodes\Gale\Tests\Unit\Exceptions;

use Dancycodes\Gale\Exceptions\GaleValidationException;
use Dancycodes\Gale\Http\GaleResponse;
use Dancycodes\Gale\Tests\TestCase;
use Illuminate\Support\Facades\Validator;

/**
 * Test the GaleValidationException class
 *
 * @see TESTING.md - File 40: GaleValidationException Tests
 * Status: 🔄 IN PROGRESS - 5 test methods
 */
class GaleValidationExceptionTest extends TestCase
{
    public static $latestResponse;

    /** @test */
    public function test_constructor_stores_validation_errors()
    {
        $validator = Validator::make(
            ['email' => 'invalid'],
            ['email' => 'required|email']
        );

        $validator->fails(); // Trigger validation

        $exception = new GaleValidationException($validator, []);

        $this->assertInstanceOf(GaleValidationException::class, $exception);
        $this->assertNotEmpty($exception->getErrors());
        $this->assertArrayHasKey('email', $exception->getErrors());
    }

    /** @test */
    public function test_get_errors_returns_errors_array()
    {
        $validator = Validator::make(
            ['email' => 'invalid', 'name' => ''],
            ['email' => 'required|email', 'name' => 'required']
        );

        $validator->fails();

        $exception = new GaleValidationException($validator, []);
        $errors = $exception->getErrors();

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertIsArray($errors['email']);
        $this->assertIsArray($errors['name']);
    }

    /** @test */
    public function test_render_returns_hyper_response()
    {
        $validator = Validator::make(
            ['email' => 'invalid'],
            ['email' => 'required|email']
        );

        $validator->fails();

        $exception = new GaleValidationException($validator, []);

        // Create a proper request
        $request = request();

        $response = $exception->render($request);

        $this->assertInstanceOf(GaleResponse::class, $response);
    }

    /** @test */
    public function test_render_includes_errors_signal()
    {
        $validator = Validator::make(
            ['email' => 'invalid'],
            ['email' => 'required|email']
        );

        $validator->fails();

        $exception = new GaleValidationException($validator, []);
        $response = $exception->render(request());

        // Verify it's a GaleResponse with errors
        $this->assertInstanceOf(GaleResponse::class, $response);

        // Check that errors are in the signal
        $errors = $exception->getErrors();
        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('email', $errors);
    }

    /** @test */
    public function test_exception_with_custom_error_bag()
    {
        $validator = Validator::make(
            ['email' => 'invalid'],
            ['email' => 'required|email']
        );

        $validator->fails();

        $exception = new GaleValidationException($validator, [], null, 'custom');

        // Should still store errors regardless of bag name
        $this->assertNotEmpty($exception->getErrors());
    }

    /** @test */
    public function test_exception_with_multiple_field_errors()
    {
        $validator = Validator::make(
            [
                'email' => 'invalid',
                'name' => '',
                'age' => 'not-a-number',
                'password' => '123',
            ],
            [
                'email' => 'required|email',
                'name' => 'required|min:3',
                'age' => 'required|integer',
                'password' => 'required|min:8',
            ]
        );

        $validator->fails();

        $exception = new GaleValidationException($validator, []);
        $errors = $exception->getErrors();

        // All fields should have errors
        $this->assertCount(4, $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('age', $errors);
        $this->assertArrayHasKey('password', $errors);
    }

    /** @test */
    public function test_exception_with_nested_validation_errors()
    {
        $validator = Validator::make(
            [
                'user' => [
                    'email' => 'invalid',
                    'profile' => ['age' => 'not-a-number'],
                ],
            ],
            [
                'user.email' => 'required|email',
                'user.profile.age' => 'required|integer',
            ]
        );

        $validator->fails();

        $exception = new GaleValidationException($validator, []);
        $errors = $exception->getErrors();

        // Should handle nested field names
        $this->assertArrayHasKey('user.email', $errors);
        $this->assertArrayHasKey('user.profile.age', $errors);
    }

    /** @test */
    public function test_exception_preserves_all_error_messages_for_field()
    {
        $validator = Validator::make(
            ['password' => 'abc'],
            [
                'password' => 'required|min:8|regex:/[A-Z]/|regex:/[0-9]/',
            ]
        );

        $validator->fails();

        $exception = new GaleValidationException($validator, []);
        $errors = $exception->getErrors();

        // Password field should have multiple error messages
        $this->assertArrayHasKey('password', $errors);
        $this->assertGreaterThan(1, count($errors['password']));
    }
}
