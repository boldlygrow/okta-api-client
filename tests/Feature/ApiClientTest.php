<?php

use BoldlyGrow\Okta\ApiClient;
use BoldlyGrow\Okta\Exceptions\BadRequestException;
use BoldlyGrow\Okta\Exceptions\ConfigurationException;
use BoldlyGrow\Okta\Exceptions\ConflictException;
use BoldlyGrow\Okta\Exceptions\ForbiddenException;
use BoldlyGrow\Okta\Exceptions\NotFoundException;
use BoldlyGrow\Okta\Exceptions\ServerErrorException;
use BoldlyGrow\Okta\Exceptions\UnauthorizedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('accepts an SSWS connection from the config when the client_id is not set', function () {
    config()->set([
        'okta-api-client.url' => 'https://example.okta.com',
        'okta-api-client.token' => str_repeat('a', 42),
        'okta-api-client.client_id' => null,
    ]);
    Http::fake([
        'example.okta.com/api/v1/users/00u1' => Http::response(['id' => '00u1']),
    ]);

    $response = ApiClient::get(uri: 'users/00u1');

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect($response->data->id)->toBe('00u1');
    expect($request->header('Authorization'))->toBe(['SSWS ' . str_repeat('a', 42)]);
});

it('accepts an OAuth connection from the config when the SSWS token is not set', function () {
    config()->set([
        'okta-api-client.url' => 'https://example.okta.com',
        'okta-api-client.token' => null,
        'okta-api-client.client_id' => '0oaTESTCLIENT',
        'okta-api-client.key_id' => 'test-kid',
        'okta-api-client.private_key' => testPrivateKey(),
    ]);
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('Bearer')),
        'example.okta.com/api/v1/users/00u1' => Http::response(['id' => '00u1']),
    ]);

    $response = ApiClient::get(uri: 'users/00u1', scope: 'okta.users.read');

    /** @var Request $api_request */
    [$api_request] = Http::recorded()->last();
    expect($response->data->id)->toBe('00u1');
    expect($api_request->header('Authorization'))->toBe(['Bearer test-access-token']);
});

it('rejects a connection without an SSWS token or an OAuth client_id', function () {
    expect(fn () => ApiClient::get(uri: 'users/00u1', connection: ['url' => 'https://example.okta.com']))
        ->toThrow(ConfigurationException::class, 'The token field is required when client id is not present.');
});

it('signs each request with a DPoP proof for the HTTP method that is sent', function (string $client_method, string $http_method) {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('DPoP')),
        'example.okta.com/api/v1/groups/00g1' => Http::response(['id' => '00g1']),
    ]);

    ApiClient::{$client_method}(
        uri: 'groups/00g1',
        data: [],
        connection: oauthConnection(['dpop' => true]),
        scope: 'okta.groups.manage'
    );

    /** @var Request $api_request */
    [$api_request] = Http::recorded()->last();
    expect($api_request->method())->toBe($http_method);
    expect($api_request->header('Authorization'))->toBe(['DPoP test-access-token']);
    expect(dpopProofClaims($api_request))->toMatchArray([
        'htm' => $http_method,
        'htu' => 'https://example.okta.com/api/v1/groups/00g1',
        'ath' => 'WXSA1LYsphIZPxnnP-TMOtF_C_nPwWp8v0tQZBMcSAU',
    ]);
})->with([
    'get' => ['get', 'GET'],
    'post' => ['post', 'POST'],
    'patch (Okta partial updates are sent as POST)' => ['patch', 'POST'],
    'put' => ['put', 'PUT'],
    'delete' => ['delete', 'DELETE'],
]);

it('retries a request once with a single new proof containing the server nonce after a use_dpop_nonce challenge', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('DPoP')),
        'example.okta.com/api/v1/users/00u1' => Http::sequence()
            ->push('', 401, [
                'WWW-Authenticate' => 'DPoP error="use_dpop_nonce", error_description="Resource server requires nonce in DPoP proof"',
                'DPoP-Nonce' => 'server-nonce',
            ])
            ->push(['id' => '00u1']),
    ]);

    $response = ApiClient::get(uri: 'users/00u1', connection: oauthConnection(['dpop' => true]), scope: 'okta.users.read');

    /** @var Request $retry_request */
    [$retry_request] = Http::recorded()->last();
    expect($response->data->id)->toBe('00u1');
    Http::assertSentCount(3);
    expect($retry_request->header('DPoP'))->toHaveCount(1);
    expect($retry_request->header('Authorization'))->toBe(['DPoP test-access-token']);
    expect(dpopProofClaims($retry_request))->toHaveKey('nonce', 'server-nonce');
});

it('does not retry a request that fails for a reason other than a nonce challenge', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('DPoP')),
        'example.okta.com/api/v1/users/00u1' => Http::response(['errorCode' => 'E0000007', 'errorSummary' => 'Not found: Resource not found: 00u1 (User)'], 404),
    ]);

    expect(fn () => ApiClient::get(uri: 'users/00u1', connection: oauthConnection(['dpop' => true]), scope: 'okta.users.read'))
        ->toThrow(NotFoundException::class, '(Reason) E0000007 Not found: Resource not found: 00u1 (User)');
    Http::assertSentCount(2);
});

it('excludes the pagination query string from the DPoP proof of each page', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('DPoP')),
        'example.okta.com/api/v1/groups?*' => Http::sequence()
            ->push([['id' => '00g1']], 200, ['link' => [
                '<https://example.okta.com/api/v1/groups?limit=1>; rel="self"',
                '<https://example.okta.com/api/v1/groups?after=00g1&limit=1>; rel="next"',
            ]])
            ->push([['id' => '00g2']], 200, ['link' => [
                '<https://example.okta.com/api/v1/groups?after=00g1&limit=1>; rel="self"',
            ]]),
    ]);

    $response = ApiClient::get(uri: 'groups', data: ['limit' => 1], connection: oauthConnection(['dpop' => true]), scope: 'okta.groups.read');

    /** @var Request $next_page_request */
    [$next_page_request] = Http::recorded()->last();
    expect(collect($response->data)->pluck('id')->all())->toBe(['00g1', '00g2']);
    expect($next_page_request->url())->toBe('https://example.okta.com/api/v1/groups?after=00g1&limit=1');
    expect(dpopProofClaims($next_page_request))->toHaveKey('htu', 'https://example.okta.com/api/v1/groups');
});

it('includes the WWW-Authenticate error in the exception when the response body is empty', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('DPoP')),
        'example.okta.com/api/v1/users/00u1' => Http::response('', 400, [
            'WWW-Authenticate' => 'DPoP algs="RS256", error="invalid_dpop_proof", error_description="\'iat\' claim in the DPoP proof JWT is invalid."',
        ]),
    ]);

    expect(fn () => ApiClient::get(uri: 'users/00u1', connection: oauthConnection(['dpop' => true]), scope: 'okta.users.read'))
        ->toThrow(BadRequestException::class, "(Reason) invalid_dpop_proof 'iat' claim in the DPoP proof JWT is invalid.");
});

it('throws a ConflictException for a 409 response', function () {
    Http::fake([
        'example.okta.com/api/v1/groups/00g1' => Http::response(['errorCode' => 'E0000001', 'errorSummary' => 'Conflict'], 409),
    ]);

    expect(fn () => ApiClient::put(uri: 'groups/00g1', connection: ['url' => 'https://example.okta.com', 'token' => str_repeat('a', 42)]))
        ->toThrow(ConflictException::class, 'PUT 409 https://example.okta.com/api/v1/groups/00g1 (Reason) E0000001 Conflict');
});

it('throws the exception for the range of a 4xx or 5xx status code without its own exception', function (int $status, string $exception) {
    Http::fake([
        'example.okta.com/api/v1/users/00u1' => Http::response('', $status),
    ]);

    expect(fn () => ApiClient::get(uri: 'users/00u1', connection: ['url' => 'https://example.okta.com', 'token' => str_repeat('a', 42)]))
        ->toThrow($exception, 'GET ' . $status . ' https://example.okta.com/api/v1/users/00u1');
})->with([
    'gone' => [410, BadRequestException::class],
    'not implemented' => [501, ServerErrorException::class],
    'bad gateway' => [502, ServerErrorException::class],
    'gateway timeout' => [504, ServerErrorException::class],
]);

it('logs a status code without its own event type with the event type for its range', function (int $status, string $event_type, string $level) {
    config()->set('okta-api-client.exceptions', false);
    Log::spy();
    Http::fake([
        'example.okta.com/api/v1/users/00u1' => Http::response('', $status),
    ]);

    $response = ApiClient::get(uri: 'users/00u1', connection: ['url' => 'https://example.okta.com', 'token' => str_repeat('a', 42)]);

    expect($response->status->code)->toBe($status);
    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $log_level, string $message, array $context) => $log_level === $level && $context['event_type'] === $event_type)
        ->once();
})->with([
    'partial content' => [206, 'okta.api.get.success', 'debug'],
    'not modified' => [304, 'okta.api.get.warning.unexpected-status', 'warning'],
    'gone' => [410, 'okta.api.get.error.client-error', 'error'],
    'bad gateway' => [502, 'okta.api.get.critical.server-error', 'critical'],
]);

it('reports a rejected SSWS token with SSWS guidance when the config uses OAuth', function () {
    config()->set('okta-api-client.client_id', '0oaTESTCLIENT');
    Http::fake([
        'example.okta.com/api/v1/users/me' => Http::response(['errorCode' => 'E0000011', 'errorSummary' => 'Invalid token provided'], 401),
    ]);

    expect(fn () => ApiClient::get(uri: 'users/me', connection: ['url' => 'https://example.okta.com', 'token' => str_repeat('a', 42)]))
        ->toThrow(UnauthorizedException::class, 'The SSWS API token was rejected.');
});

it('reports a rejected OAuth token with OAuth guidance when the config uses an SSWS token', function () {
    config()->set([
        'okta-api-client.client_id' => null,
        'okta-api-client.token' => str_repeat('a', 42),
    ]);
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('Bearer')),
        'example.okta.com/api/v1/users/me' => Http::response(['errorCode' => 'E0000011', 'errorSummary' => 'Invalid token provided'], 401),
    ]);

    expect(fn () => ApiClient::get(uri: 'users/me', connection: oauthConnection(), scope: 'okta.users.read'))
        ->toThrow(UnauthorizedException::class, 'The OAuth access token was rejected.');
});

it('mints a new access token for the next request after Okta rejects the cached token', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::sequence()
            ->push(tokenResponseBody('Bearer', 'rejected-access-token'))
            ->push(tokenResponseBody('Bearer', 'new-access-token')),
        'example.okta.com/api/v1/users/00u1' => Http::sequence()
            ->push('', 401, ['WWW-Authenticate' => 'Bearer error="invalid_token", error_description="The access token is invalid."'])
            ->push(['id' => '00u1']),
    ]);
    expect(fn () => ApiClient::get(uri: 'users/00u1', connection: oauthConnection(), scope: 'okta.users.read'))
        ->toThrow(UnauthorizedException::class);

    $response = ApiClient::get(uri: 'users/00u1', connection: oauthConnection(), scope: 'okta.users.read');

    /** @var Request $api_request */
    [$api_request] = Http::recorded()->last();
    expect($response->data->id)->toBe('00u1');
    expect($api_request->header('Authorization'))->toBe(['Bearer new-access-token']);
});

it('keeps the cached access token when a request fails for a reason other than a rejected token', function () {
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('Bearer')),
        'example.okta.com/api/v1/users/00u1' => Http::sequence()
            ->push(['errorCode' => 'E0000006', 'errorSummary' => 'You do not have permission to perform the requested action'], 403)
            ->push(['id' => '00u1']),
    ]);
    expect(fn () => ApiClient::get(uri: 'users/00u1', connection: oauthConnection(), scope: 'okta.users.read'))
        ->toThrow(ForbiddenException::class);

    ApiClient::get(uri: 'users/00u1', connection: oauthConnection(), scope: 'okta.users.read');

    Http::assertSentCount(3);
});

it('requests the token with the given scope when testing the connection', function () {
    config()->set([
        'okta-api-client.url' => 'https://example.okta.com',
        'okta-api-client.client_id' => '0oaTESTCLIENT',
        'okta-api-client.key_id' => 'test-kid',
        'okta-api-client.private_key' => testPrivateKey(),
    ]);
    Http::fake([
        'example.okta.com/oauth2/v1/token' => Http::response(tokenResponseBody('Bearer')),
        'example.okta.com/api/v1/org' => Http::response(['id' => '00o1']),
    ]);

    $connected = ApiClient::testConnection(scope: 'okta.orgs.read');

    expect($connected)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://example.okta.com/oauth2/v1/token'
        && $request['scope'] === 'okta.orgs.read');
});
