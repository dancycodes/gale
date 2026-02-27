<?php

namespace Dancycodes\Gale\Exceptions;

use Dancycodes\Gale\Http\GaleResponse;
use Illuminate\Validation\ValidationException;

/**
 * Reactive Message Exception
 *
 * Exception thrown during reactive state validation failures, extending Laravel's
 * ValidationException with reactive response rendering. Automatically renders
 * SSE responses containing the 'messages' state for client-side display via
 * the x-message directive.
 *
 * Supports selective field clearing - when validating specific fields, only those
 * fields' messages are cleared while preserving messages for other fields. This
 * enables partial form validation without losing context on unvalidated fields.
 *
 * Message structure is flattened to first message per field for simplicity:
 * Laravel: ['email' => ['Invalid email', 'Already taken']]
 * Gale:    ['email' => 'Invalid email']
 *
 * Usage with x-message directive:
 * <span x-message="email" class="text-red-500"></span>
 *
 * @see \Dancycodes\Gale\GaleServiceProvider::registerRequestMacros()
 */
class GaleMessageException extends ValidationException
{
    /**
     * Flattened validation messages indexed by field name
     *
     * @var array<string, string>
     */
    protected array $messages;

    /**
     * Initialize message exception from validator instance
     *
     * Extracts validation error messages from validator, merges with pre-cleared
     * messages (for selective clearing), and flattens to first message per field.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator Validator instance with errors
     * @param array<string, string> $clearedMessages Pre-cleared messages for fields being validated
     * @param \Symfony\Component\HttpFoundation\Response|null $response Optional custom response
     * @param string $errorBag Error bag name for multiple validation contexts
     */
    public function __construct(
        \Illuminate\Contracts\Validation\Validator $validator,
        array $clearedMessages = [],
        ?\Symfony\Component\HttpFoundation\Response $response = null,
        string $errorBag = 'default'
    ) {
        parent::__construct($validator, $response, $errorBag);

        // Flatten validator errors to first message per field
        $flatErrors = [];
        foreach ($validator->errors()->toArray() as $field => $errorMessages) {
            $flatErrors[$field] = $errorMessages[0] ?? '';
        }

        // Merge pre-cleared messages with new validation errors
        // Pre-cleared keeps other field messages, new errors overwrite cleared fields
        $this->messages = array_merge($clearedMessages, $flatErrors);
    }

    /**
     * Render exception as reactive SSE response with messages state
     *
     * Generates SSE response containing 'messages' state for client-side display.
     * The x-message directive automatically displays these messages next to
     * corresponding form fields.
     *
     * @param \Illuminate\Http\Request $request Current HTTP request
     *
     * @return \Dancycodes\Gale\Http\GaleResponse SSE response with messages state
     */
    public function render(\Illuminate\Http\Request $request): GaleResponse
    {
        return gale()->state('messages', $this->messages);
    }

    /**
     * Retrieve flattened validation messages
     *
     * Returns message array with field names as keys and single message strings
     * as values (first validation error per field).
     *
     * @return array<string, string> Field name to message string mapping
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
