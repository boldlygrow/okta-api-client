<?php

use BoldlyGrow\Okta\DpopProof;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

it('embeds only the public key in the proof header and signs the proof with the matching private key', function () {
    $key_details = openssl_pkey_get_details(openssl_pkey_get_private(testPrivateKey()));

    $proof = DpopProof::create(testPrivateKey(), 'GET', 'https://example.okta.com/api/v1/users');

    [$encoded_header, $encoded_claims, $encoded_signature] = explode('.', $proof);
    expect(decodeJwt($proof)['header'])->toEqual([
        'typ' => 'dpop+jwt',
        'alg' => 'RS256',
        'jwk' => [
            'kty' => 'RSA',
            'e' => 'AQAB',
            'n' => rtrim(strtr(base64_encode($key_details['rsa']['n']), '+/', '-_'), '='),
        ],
    ]);
    expect(openssl_verify(
        $encoded_header . '.' . $encoded_claims,
        base64_decode(strtr($encoded_signature, '-_', '+/')),
        $key_details['key'],
        OPENSSL_ALGO_SHA256
    ))->toBe(1);
});

it('binds the proof to the uppercased method and the URL without its query string or fragment', function () {
    $proof = DpopProof::create(testPrivateKey(), 'post', 'https://example.okta.com/api/v1/groups?after=00g1&limit=1#top');

    expect(decodeJwt($proof)['claims'])
        ->toHaveKey('htm', 'POST')
        ->toHaveKey('htu', 'https://example.okta.com/api/v1/groups');
});

it('percent-decodes the URL path in the htu claim to match the decoded request URL', function () {
    $proof = DpopProof::create(testPrivateKey(), 'GET', 'https://example.okta.com/api/v1/users/first.last%40example.com');

    expect(decodeJwt($proof)['claims'])
        ->toHaveKey('htu', 'https://example.okta.com/api/v1/users/first.last@example.com');
});

it('adds the nonce and the access token hash when they are given', function () {
    $proof = DpopProof::create(
        private_key: testPrivateKey(),
        http_method: 'GET',
        http_url: 'https://example.okta.com/api/v1/users',
        nonce: 'server-nonce',
        access_token: 'test-access-token'
    );

    expect(decodeJwt($proof)['claims'])
        ->toHaveKey('nonce', 'server-nonce')
        ->toHaveKey('ath', 'WXSA1LYsphIZPxnnP-TMOtF_C_nPwWp8v0tQZBMcSAU');
});

it('omits the nonce and the access token hash when they are not given', function () {
    $proof = DpopProof::create(testPrivateKey(), 'POST', 'https://example.okta.com/oauth2/v1/token');

    expect(decodeJwt($proof)['claims'])
        ->not->toHaveKey('nonce')
        ->not->toHaveKey('ath');
});

it('stamps each proof with the current time and a unique jti', function () {
    $this->travelTo('2026-01-01 00:00:00');

    $first_proof = decodeJwt(DpopProof::create(testPrivateKey(), 'GET', 'https://example.okta.com/api/v1/users'))['claims'];
    $second_proof = decodeJwt(DpopProof::create(testPrivateKey(), 'GET', 'https://example.okta.com/api/v1/users'))['claims'];

    expect($first_proof['iat'])->toBe(1767225600);
    expect($first_proof['jti'])->toBeUuid()->not->toBe($second_proof['jti']);
});

it('returns the server nonce only for a use_dpop_nonce challenge', function (int $status, array $headers, string $body, ?string $expected_nonce) {
    $response = new Response(new Psr7Response($status, $headers, $body));

    $nonce = DpopProof::nonceChallenge($response);

    expect($nonce)->toBe($expected_nonce);
})->with([
    'token endpoint challenge in the body' => [
        400, ['DPoP-Nonce' => 'server-nonce'], '{"error":"use_dpop_nonce"}', 'server-nonce',
    ],
    'resource server challenge in the WWW-Authenticate header' => [
        401, ['DPoP-Nonce' => 'server-nonce', 'WWW-Authenticate' => 'DPoP error="use_dpop_nonce"'], '', 'server-nonce',
    ],
    'challenge without a DPoP-Nonce header' => [
        400, [], '{"error":"use_dpop_nonce"}', null,
    ],
    'different error with a DPoP-Nonce header' => [
        400, ['DPoP-Nonce' => 'server-nonce'], '{"error":"invalid_dpop_proof"}', null,
    ],
]);

it('generates a 2048-bit RSA private key', function () {
    $private_key = DpopProof::generatePrivateKey();

    expect(openssl_pkey_get_details(openssl_pkey_get_private($private_key)))
        ->toMatchArray(['bits' => 2048, 'type' => OPENSSL_KEYTYPE_RSA]);
});
