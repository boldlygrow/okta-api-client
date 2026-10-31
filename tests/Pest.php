<?php

use BoldlyGrow\Okta\Tests\TestCase;
use Illuminate\Http\Client\Request;

uses(TestCase::class)->in(__DIR__ . '/Feature');

/**
 * An RSA private key for signing client assertions, generated once per test run since key generation is slow.
 */
function testPrivateKey(): string
{
    static $private_key = null;

    if ($private_key === null) {
        $key_resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        openssl_pkey_export($key_resource, $private_key);
    }

    return $private_key;
}

/**
 * An OAuth 2.0 connection array for a fake Okta org at https://example.okta.com.
 *
 * @param  array<string, mixed>  $overrides
 *
 * @return array<string, mixed>
 */
function oauthConnection(array $overrides = []): array
{
    return array_merge([
        'url' => 'https://example.okta.com',
        'client_id' => '0oaTESTCLIENT',
        'key_id' => 'test-kid',
        'private_key' => testPrivateKey(),
        'dpop' => false,
    ], $overrides);
}

/**
 * A successful Okta token endpoint response body.
 *
 * @return array<string, mixed>
 */
function tokenResponseBody(string $token_type = 'DPoP', string $access_token = 'test-access-token'): array
{
    return [
        'token_type' => $token_type,
        'expires_in' => 3600,
        'access_token' => $access_token,
        'scope' => 'okta.users.read',
    ];
}

/**
 * Decode the header and claims of a JWT without verifying its signature.
 *
 * @return array{header: array<string, mixed>, claims: array<string, mixed>}
 */
function decodeJwt(string $jwt): array
{
    [$header, $claims] = explode('.', $jwt);

    return [
        'header' => json_decode(base64_decode(strtr($header, '-_', '+/')), true),
        'claims' => json_decode(base64_decode(strtr($claims, '-_', '+/')), true),
    ];
}

/**
 * The decoded claims of the DPoP proof sent with a recorded request.
 *
 * @return array<string, mixed>
 */
function dpopProofClaims(Request $request): array
{
    return decodeJwt($request->header('DPoP')[0])['claims'];
}
