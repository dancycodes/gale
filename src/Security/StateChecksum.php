<?php

namespace Dancycodes\Gale\Security;

use RuntimeException;

/**
 * State Checksum — HMAC-SHA256 Integrity Verification (F-013)
 *
 * Signs outbound Alpine component state with HMAC-SHA256 using the Laravel
 * application key. Every Gale state patch includes a `_checksum` field;
 * every incoming Gale request with state must present a matching checksum.
 *
 * Signing flow:
 *   Server builds state → HMAC-SHA256(canonical_json, APP_KEY) → attach _checksum
 *   Client sends state + _checksum → Server recomputes HMAC → compare → accept or reject
 *
 * The canonical JSON representation uses JSON_FORCE_OBJECT and sorted keys so that
 * key ordering differences do not produce different signatures (BR-013.3).
 *
 * Timing-safe comparison via hash_equals() prevents timing attacks (BR-013.8).
 */
class StateChecksum
{
    /**
     * The reserved state key used to transport the checksum
     */
    public const KEY = '_checksum';

    /**
     * Compute an HMAC-SHA256 signature for the given state array
     *
     * The state is serialized using a canonical form (keys sorted recursively,
     * compact JSON without whitespace) so that identical logical state always
     * produces an identical signature regardless of key insertion order (BR-013.3).
     *
     * The HMAC secret is derived from config('app.key') (BR-013.2).
     *
     * @param  array<string, mixed>  $state  The state to sign (must NOT include _checksum)
     * @return string Hex-encoded HMAC-SHA256 digest
     *
     * @throws RuntimeException When app.key is empty or not configured
     */
    public static function compute(array $state): string
    {
        $secret = static::deriveSecret();
        $canonical = static::canonicalize($state);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Verify that a submitted checksum matches the state
     *
     * Recomputes the HMAC over the state (with _checksum removed) and compares
     * the result to the submitted checksum using timing-safe hash_equals() (BR-013.8).
     *
     * @param  array<string, mixed>  $state  Incoming state (may include _checksum)
     * @param  string  $checksum  The submitted checksum to verify
     * @return bool True when the checksum is valid; false otherwise
     */
    public static function verify(array $state, string $checksum): bool
    {
        // Strip the reserved key before recomputing — it was not part of the signed payload
        $stateWithoutChecksum = $state;
        unset($stateWithoutChecksum[self::KEY]);

        $expected = static::compute($stateWithoutChecksum);

        return hash_equals($expected, $checksum);
    }

    /**
     * Attach a freshly computed checksum to a state array
     *
     * Computes the HMAC over $state (as-is, without _checksum) and returns
     * a new array containing all original keys plus `_checksum` appended at
     * the end (BR-013.1, BR-013.4).
     *
     * @param  array<string, mixed>  $state  State to sign
     * @return array<string, mixed> State with `_checksum` appended
     */
    public static function sign(array $state): array
    {
        $state[self::KEY] = static::compute($state);

        return $state;
    }

    /**
     * Produce a canonical JSON string for consistent HMAC computation
     *
     * Keys are sorted recursively so that the JSON representation is
     * deterministic regardless of insertion order. Produces compact JSON
     * (no whitespace) for efficiency (BR-013.3).
     *
     * @param  array<string, mixed>  $state
     */
    protected static function canonicalize(array $state): string
    {
        $sorted = static::sortKeysRecursively($state);

        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // json_encode always succeeds for valid PHP arrays; the cast silences PHPStan
        return (string) $json;
    }

    /**
     * Recursively sort array keys in ascending order
     *
     * Only associative arrays are sorted; numerically indexed arrays retain their
     * order because their position is semantically significant (e.g., items lists).
     */
    protected static function sortKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // Detect associative array (has at least one non-integer key)
        $isAssociative = array_keys($value) !== range(0, count($value) - 1);

        $mapped = array_map([static::class, 'sortKeysRecursively'], $value);

        if ($isAssociative) {
            ksort($mapped);
        }

        return $mapped;
    }

    /**
     * Derive the HMAC secret from the application key
     *
     * Laravel stores the APP_KEY with a "base64:" prefix for the encoded form.
     * We strip the prefix and base64-decode to get the raw 32-byte key.
     * A plain key (no prefix) is used as-is (BR-013.2).
     *
     * @return string Raw binary HMAC secret
     *
     * @throws RuntimeException When the application key is empty or not configured
     */
    protected static function deriveSecret(): string
    {
        $appKey = config('app.key', '');

        if (! is_string($appKey) || $appKey === '') {
            throw new RuntimeException(
                'Gale checksum: app.key is not configured. Set APP_KEY in your .env file.'
            );
        }

        if (str_starts_with($appKey, 'base64:')) {
            /** @var string $decoded */
            $decoded = base64_decode(substr($appKey, 7), strict: true);

            return $decoded !== false ? $decoded : $appKey;
        }

        return $appKey;
    }
}
