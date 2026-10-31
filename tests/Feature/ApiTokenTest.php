<?php

use BoldlyGrow\Okta\ApiToken;
use BoldlyGrow\Okta\Exceptions\ConfigurationException;
use BoldlyGrow\Okta\Exceptions\ScopeException;
use BoldlyGrow\Okta\Exceptions\UnauthorizedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('mints a Bearer token with a private_key_jwt client assertion when DPoP is disabled', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('Bearer')),
    ]);

    $headers = ApiToken::authorizationHeaders(
        scope: 'okta.users.read',
        connection: oauthConnection(),
        http_method: 'GET',
        http_url: 'https://example.okta.com/api/v1/users'
    );

    /** @var Request $token_request */
    [$token_request] = Http::recorded()->sole();
    $client_assertion = decodeJwt($token_request['client_assertion']);
    expect($headers)->toBe(['Authorization' => 'Bearer test-access-token']);
    expect($token_request->hasHeader('DPoP'))->toBeFalse();
    expect($token_request->data())->toMatchArray([
        'grant_type' => 'client_credentials',
        'scope' => 'okta.users.read',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
    ]);
    expect($client_assertion['header'])->toHaveKey('kid', 'test-kid');
    expect($client_assertion['claims'])->toMatchArray([
        'iss' => '0oaTESTCLIENT',
        'sub' => '0oaTESTCLIENT',
        'aud' => 'https://example.okta.com/oauth2/v1/token',
    ]);
});

it('retries the token request with the server nonce and a new client assertion when DPoP is enabled', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::sequence()
            ->push(['error' => 'use_dpop_nonce', 'error_description' => 'Authorization server requires nonce in DPoP proof.'], 400, ['DPoP-Nonce' => 'server-nonce'])
            ->push(tokenResponseBody('DPoP')),
    ]);

    $access_token = ApiToken::create(scope: 'okta.users.read', connection: oauthConnection(['dpop' => true]));

    /** @var array{0: Request, 1: Request} $token_requests */
    $token_requests = Http::recorded()->map(fn (array $pair) => $pair[0])->all();
    [$first_request, $retry_request] = $token_requests;
    expect($access_token)->toBe('test-access-token');
    expect($token_requests)->toHaveCount(2);
    expect(dpopProofClaims($first_request))
        ->toMatchArray(['htm' => 'POST', 'htu' => 'https://example.okta.com/oauth2/v1/token'])
        ->not->toHaveKey('nonce');
    expect(dpopProofClaims($retry_request))->toHaveKey('nonce', 'server-nonce');
    expect(decodeJwt($retry_request['client_assertion'])['claims']['jti'])
        ->not->toBe(decodeJwt($first_request['client_assertion'])['claims']['jti']);
});

it('enables DPoP by default when the connection array omits the dpop key', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('DPoP')),
    ]);

    $connection = oauthConnection();
    unset($connection['dpop']);

    $headers = ApiToken::authorizationHeaders(
        scope: 'okta.users.read',
        connection: $connection,
        http_method: 'GET',
        http_url: 'https://example.okta.com/api/v1/users'
    );

    /** @var Request $token_request */
    [$token_request] = Http::recorded()->sole();
    expect($token_request->hasHeader('DPoP'))->toBeTrue();
    expect($headers['Authorization'])->toBe('DPoP test-access-token');
});

it('enables DPoP by default in the package config', function () {
    expect(config('okta-api-client.dpop'))->toBeTrue();
});

it('reuses the cached token and its DPoP key for later requests', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('DPoP')),
    ]);
    $connection = oauthConnection(['dpop' => true]);

    $first_headers = ApiToken::authorizationHeaders('okta.users.read', $connection, 'GET', 'https://example.okta.com/api/v1/users');
    $second_headers = ApiToken::authorizationHeaders('okta.users.read', $connection, 'GET', 'https://example.okta.com/api/v1/users');

    Http::assertSentCount(1);
    expect(decodeJwt($second_headers['DPoP'])['header']['jwk'])->toBe(decodeJwt($first_headers['DPoP'])['header']['jwk']);
});

it('mints a new token after the cached token is forgotten', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::sequence()
            ->push(tokenResponseBody('Bearer', 'forgotten-access-token'))
            ->push(tokenResponseBody('Bearer', 'new-access-token')),
    ]);
    ApiToken::create(scope: 'okta.users.read', connection: oauthConnection());

    ApiToken::forget(scope: 'okta.users.read', connection: oauthConnection());

    expect(ApiToken::create(scope: 'okta.users.read', connection: oauthConnection()))->toBe('new-access-token');
});

it('mints a new DPoP-bound token instead of reusing a cached Bearer token after DPoP is enabled', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::sequence()
            ->push(tokenResponseBody('Bearer', 'bearer-access-token'))
            ->push(tokenResponseBody('DPoP', 'dpop-access-token')),
    ]);
    ApiToken::create(scope: 'okta.users.read', connection: oauthConnection(['dpop' => false]));

    $headers = ApiToken::authorizationHeaders('okta.users.read', oauthConnection(['dpop' => true]), 'GET', 'https://example.okta.com/api/v1/users');

    expect($headers['Authorization'])->toBe('DPoP dpop-access-token');
});

it('falls back to the Bearer scheme when Okta issues a Bearer token with DPoP enabled', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('Bearer')),
    ]);

    $headers = ApiToken::authorizationHeaders('okta.users.read', oauthConnection(['dpop' => true]), 'GET', 'https://example.okta.com/api/v1/users');

    expect($headers)->toBe(['Authorization' => 'Bearer test-access-token']);
});

it('explains that DPoP must be enabled when Okta requires a DPoP proof', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(['error' => 'invalid_dpop_proof', 'error_description' => 'The DPoP proof JWT header is missing.'], 400),
    ]);

    expect(fn () => ApiToken::create(scope: 'okta.users.read', connection: oauthConnection()))
        ->toThrow(UnauthorizedException::class, '(Reason) The DPoP proof JWT header is missing. (Solution) The Okta service app requires DPoP. Set `OKTA_API_DPOP=true`');
});

it('throws an UnauthorizedException when a successful token endpoint response is not JSON', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response('<html>Proxy login page</html>', 200),
    ]);

    expect(fn () => ApiToken::create(scope: 'okta.users.read', connection: oauthConnection()))
        ->toThrow(UnauthorizedException::class, '(Reason) Unknown response in the sendTokenRequest method.');
});

it('throws a ScopeException without requesting a token when no scope is given', function () {
    expect(fn () => ApiToken::create(scope: null, connection: oauthConnection()))
        ->toThrow(ScopeException::class, 'An OAuth scope is required but was not provided.');
});

it('rejects a dpop setting that is not a boolean', function () {
    expect(fn () => ApiToken::create(scope: 'okta.users.read', connection: oauthConnection(['dpop' => 'sometimes'])))
        ->toThrow(ConfigurationException::class, 'The dpop field must be true or false.');
});

it('throws a ConfigurationException without requesting a token when no key_id is given', function () {
    Http::fake();

    expect(fn () => ApiToken::create(scope: 'okta.users.read', connection: oauthConnection(['key_id' => null])))
        ->toThrow(ConfigurationException::class, 'The key_id (OKTA_API_KEY_ID) is required.');
    Http::assertNothingSent();
});

it('caches the token for the configured number of minutes', function () {
    config()->set('okta-api-client.token_cache_minutes', 30);
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::sequence()
            ->push(tokenResponseBody('Bearer', 'first-access-token'))
            ->push(tokenResponseBody('Bearer', 'second-access-token')),
    ]);
    ApiToken::create(scope: 'okta.users.read', connection: oauthConnection());

    $this->travel(29)->minutes();
    $cached_token = ApiToken::create(scope: 'okta.users.read', connection: oauthConnection());
    $this->travel(2)->minutes();
    $new_token = ApiToken::create(scope: 'okta.users.read', connection: oauthConnection());

    expect($cached_token)->toBe('first-access-token');
    expect($new_token)->toBe('second-access-token');
});

it('rejects a token_cache_minutes value that is not between 1 and 59', function (mixed $cache_minutes) {
    config()->set('okta-api-client.token_cache_minutes', $cache_minutes);
    Http::fake();

    expect(fn () => ApiToken::create(scope: 'okta.users.read', connection: oauthConnection()))
        ->toThrow(ConfigurationException::class, 'must be a whole number between 1 and 59');
    Http::assertNothingSent();
})->with([
    'zero' => 0,
    'longer than the token lifetime' => 60,
    'not a number' => 'an hour',
]);
