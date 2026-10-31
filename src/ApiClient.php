<?php

/**
 * @copyright Jefferson Martin
 * @license MIT <https://spdx.org/licenses/MIT.html>
 *
 * @link https://github.com/boldlygrow/okta-api-client
 */

namespace BoldlyGrow\Okta;

use BoldlyGrow\AuditLog\AuditLog;
use BoldlyGrow\Okta\Exceptions\BadRequestException;
use BoldlyGrow\Okta\Exceptions\ConfigurationException;
use BoldlyGrow\Okta\Exceptions\ConflictException;
use BoldlyGrow\Okta\Exceptions\ForbiddenException;
use BoldlyGrow\Okta\Exceptions\MethodNotAllowedException;
use BoldlyGrow\Okta\Exceptions\NotFoundException;
use BoldlyGrow\Okta\Exceptions\PreconditionFailedException;
use BoldlyGrow\Okta\Exceptions\RateLimitException;
use BoldlyGrow\Okta\Exceptions\ServerErrorException;
use BoldlyGrow\Okta\Exceptions\ServiceUnavailableException;
use BoldlyGrow\Okta\Exceptions\UnauthorizedException;
use BoldlyGrow\Okta\Exceptions\UnprocessableException;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ApiClient
{
    /**
     * Test the connection to the Okta API using a simple API endpoint
     *
     * Example Usage:
     * ```php
     * use BoldlyGrow\Okta\ApiClient;
     * ApiClient::testConnection();
     * ```
     *
     * @link https://developer.okta.com/docs/reference/api/org/#get-org-settings
     *
     * @param  array        $connection  (optional) An array with `url` and `token`. If not set, the
     *                                   `config('okta-api-client')` array will be used that uses the
     *                                   OKTA_API_* variables from your .env file.
     * @param  string|null  $scope       (optional) A space-delimited OAuth scope string that grants access to the
     *                                   `test_uri` endpoint (ex. `okta.orgs.read` for the default `org` endpoint). This is
     *                                   required when authenticating with OAuth and ignored when using an SSWS token.
     *
     * @throws ConfigurationException
     */
    public static function testConnection(array $connection = [], ?string $scope = null): bool
    {
        $response = self::get(
            uri: config('okta-api-client.test_uri'),
            connection: $connection,
            scope: $scope
        );

        AuditLog::create(
            event_type: 'okta.api.test.success',
            level: 'debug',
            message: 'Success',
            method: __METHOD__,
            record_provider_id: $response->data->id,
            transaction: false
        );

        return true;
    }

    /**
     * Validate connection config array
     *
     * @param  array  $connection  An array with `url` and `token`.
     */
    private static function validateConnection(array $connection): array
    {
        if (empty($connection)) {
            $connection = config('okta-api-client');
        }

        $validator = Validator::make($connection, [
            'url' => 'required|url:https',
            'token' => 'nullable|required_without:client_id|alpha_dash|size:42',
            'client_id' => 'nullable|required_without:token|string',
            'dpop' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            AuditLog::create(
                errors: $validator->errors()->all(),
                event_type: 'okta.api.validate.error',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: true
            );
            throw new ConfigurationException(implode(' ', [
                'Okta API configuration validation error.',
                'This occurred in ' . __METHOD__ . '.',
                '(Solution) ' . implode(' ', $validator->errors()->all()),
            ]));
        }

        // Merge validated keys over the source connection so OAuth keys that have no rule here
        // (key_id, private_key, private_key_path) survive to getRequestHeaders() and
        // ApiToken::create(). $validator->validated() alone would drop them.
        return array_merge($connection, $validator->validated());
    }

    /**
     * Okta API Get Request
     *
     * Example Usage:
     * ```php
     * use BoldlyGrow\Okta\ApiClient;
     * $response = ApiClient::get(
     *     uri: 'users/' . $id,
     *     data: [
     *         'limit' => 200
     *     ]
     * );
     * ```
     *
     * @param  string       $uri         The URI with or without leading slash after `/api/v1/`
     * @param  array        $data        (optional) Query data to apply to GET request
     * @param  array        $connection  (optional) An array with `url` and `token`. If not set, the
     *                                   `config('okta-api-client')` array will be used that uses the
     *                                   OKTA_API_* variables from your .env file.
     * @param  string|null  $scope       (optional) A space-delimited OAuth scope string (ex. `okta.users.read`). This is
     *                                   required when authenticating with OAuth and ignored when using an SSWS token.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function get(
        string $uri,
        array $data = [],
        array $connection = [],
        ?string $scope = null,
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();
        $request_url = $connection['url'] . '/api/v1/' . ltrim($uri, '/');

        try {
            $request = self::pendingRequest($connection, $scope, 'GET', $request_url)->get(
                url: $request_url,
                query: $data
            );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                uri: ltrim($uri, '/')
            );
        }

        // Parse API Response and convert to returnable object with expected format
        $response = self::parseApiResponse($request);
        $query_string = $data ? '?' . http_build_query($data) : '';
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/') . $query_string,
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'get',
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/') . $query_string,
            response: $response,
            connection: $connection
        );

        // If the response is a paginated response
        if (self::checkForPagination($response->headers) == true) {
            AuditLog::create(
                event_type: 'okta.api.get.process.pagination.started',
                level: 'debug',
                message: 'Paginated Results Process Started',
                metadata: [
                    'okta_request_id' => $response->headers['x-okta-request-id'] ?? null,
                    'uri' => ltrim($uri, '/'),
                ],
                method: __METHOD__,
                transaction: false
            );

            // Get paginated URL and use getPaginatedResults to loop through all paginated requests
            $response->paginated_results = self::getPaginatedResults(
                connection: $connection,
                paginated_url: self::generateNextPaginatedResultUrl($response->headers),
                records: $response->data,
                scope: $scope
            );

            // Unset property for original request body
            unset($response->body);

            // Parse API Response and convert to returnable object with expected format
            $response = self::parseApiResponse($response);

            $count_records = is_countable($response->data) ? count($response->data) : null;
            $duration_ms_per_record = $count_records ? (int) ($event_ms->diffInMilliseconds() / $count_records) : null;

            AuditLog::create(
                count_records: $count_records,
                duration_ms: $event_ms,
                duration_ms_per_record: $duration_ms_per_record,
                event_type: 'okta.api.get.process.pagination.finished',
                level: 'debug',
                message: 'Paginated Results Process Complete',
                metadata: [
                    'okta_request_id' => $response->headers['x-okta-request-id'] ?? null,
                    'uri' => ltrim($uri, '/'),
                ],
                method: __METHOD__,
                transaction: false
            );
        }

        return $response;
    }

    /**
     * Okta API POST Request
     *
     * This method is called from other services to perform a POST request and return a structured object.
     *
     * Example Usage:
     * ```php
     * use BoldlyGrow\Okta\ApiClient;
     * $response = ApiClient::post(
     *     uri: 'groups',
     *     data: [
     *         'profile' => [
     *             'name' => 'Hack The Planet Elite Members',
     *             'description' => 'This is for all team members that are elite.'
     *         ]
     *     ]
     * );
     * ```
     *
     * @param  string       $uri         The URI without leading slash after `/api/v1/`
     * @param  array        $data        (optional) Post Body array
     * @param  array        $connection  (optional) An array with `url` and `token`. If not set, the
     *                                   `config('okta-api-client')` array will be used that uses the
     *                                   OKTA_API_* variables from your .env file.
     * @param  string|null  $scope       (optional) A space-delimited OAuth scope string (ex. `okta.users.read`). This is
     *                                   required when authenticating with OAuth and ignored when using an SSWS token.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function post(
        string $uri,
        array $data = [],
        array $connection = [],
        ?string $scope = null
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();
        $request_url = $connection['url'] . '/api/v1/' . ltrim($uri, '/');

        try {
            $request = self::pendingRequest($connection, $scope, 'POST', $request_url)->post(
                url: $request_url,
                data: $data
            );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                uri: ltrim($uri, '/')
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'post',
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
            response: $response,
            connection: $connection
        );

        return $response;
    }

    /**
     * Okta API PATCH Request
     *
     * This method is called from other services to perform a PATCH request to
     * update one or more attributes on an existing record.
     *
     * Partial updates are not supported on all endpoints. For example, they are
     * supported on users endpoint, but not on groups.
     *
     * The Okta API does not support PATCH requests and uses non-standard POST
     * requests for partial updates. The `patch()` method is used in the Okta
     * API Client for improved developer experience, and we use the Laravel
     * HTTP Client `post()` method behind the scenes. You can use the `post()`
     * method in the Okta API Client for updating records without any issues,
     * this is just an overlay to comply with industry conventions for `PATCH`.
     *
     * Example Usage:
     * ```php
     * use BoldlyGrow\Okta\ApiClient;
     * $group_id = '00g1ab2c4d4e5f6g7h8i';
     * $response = ApiClient::patch(
     *     uri: 'groups/' . $group_id',
     *     data: [
     *         'profile' => [
     *             'description' => 'This is for all team members that are not quite elite.'
     *         ]
     *     ]
     * );
     * ```
     *
     * @param  string       $uri         The URI without leading slash after `/api/v1/`
     * @param  array        $data        (optional) Optional request data to send with PUT request
     * @param  array        $connection  (optional) An array with `url` and `token`. If not set, the
     *                                   `config('okta-api-client')` array will be used that uses the
     *                                   OKTA_API_* variables from your .env file.
     * @param  string|null  $scope       (optional) A space-delimited OAuth scope string (ex. `okta.users.read`). This is
     *                                   required when authenticating with OAuth and ignored when using an SSWS token.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function patch(
        string $uri,
        array $data = [],
        array $connection = [],
        ?string $scope = null
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();
        $request_url = $connection['url'] . '/api/v1/' . ltrim($uri, '/');

        try {
            $request = self::pendingRequest($connection, $scope, 'POST', $request_url)->post(
                url: $request_url,
                data: $data
            );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                uri: ltrim($uri, '/')
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'patch|post',
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
            response: $response,
            connection: $connection
        );

        return $response;
    }

    /**
     * Okta API PUT Request
     *
     * This method is called from other services to perform a PUT request and
     * return a structured object.
     *
     * Example Usage:
     * ```php
     * use BoldlyGrow\Okta\ApiClient;
     * $group_id = '00g1ab2c4d4e5f6g7h8i';
     * $response = ApiClient::put(
     *     uri: 'groups/' . $group_id',
     *     data: [
     *         'profile' => [
     *             'name' => 'Hack The Planet Apprentice Members',
     *             'description' => 'This is for all team members that are not quite elite.'
     *         ]
     *     ]
     * );
     * ```
     *
     * @param  string       $uri         The URI without leading slash after `/api/v1/`
     * @param  array        $data        (optional) Optional request data to send with PUT request
     * @param  array        $connection  (optional) An array with `url` and `token`. If not set, the
     *                                   `config('okta-api-client')` array will be used that uses the
     *                                   OKTA_API_* variables from your .env file.
     * @param  string|null  $scope       (optional) A space-delimited OAuth scope string (ex. `okta.users.read`). This is
     *                                   required when authenticating with OAuth and ignored when using an SSWS token.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function put(
        string $uri,
        array $data = [],
        array $connection = [],
        ?string $scope = null
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();
        $request_url = $connection['url'] . '/api/v1/' . ltrim($uri, '/');

        try {
            $request = self::pendingRequest($connection, $scope, 'PUT', $request_url)->put(
                url: $request_url,
                data: $data
            );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                uri: ltrim($uri, '/')
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'put',
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
            response: $response,
            connection: $connection
        );

        return $response;
    }

    /**
     * Okta API DELETE Request
     *
     * This method is called from other services to perform a DELETE request and return a structured object.
     *
     * Example Usage:
     * ```php
     * use BoldlyGrow\Okta\ApiClient;
     * $group_id = '00g1ab2c4d4e5f6g7h8i';
     * $response = ApiClient::delete(
     *     connection: $okta_organization->connection_config,
     *     uri: '/groups/' . $group_id',
     *     data: []
     * );
     * ```
     *
     * @param  string       $uri         The URI without leading slash after `/api/v1/`
     * @param  array        $data        Optional request data to send with DELETE request
     * @param  array        $connection  (optional) An array with `url` and `token`. If not set, the
     *                                   `config('okta-api-client')` array will be used that uses the
     *                                   OKTA_API_* variables from your .env file.
     * @param  string|null  $scope       (optional) A space-delimited OAuth scope string (ex. `okta.users.read`). This is
     *                                   required when authenticating with OAuth and ignored when using an SSWS token.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function delete(
        string $uri,
        array $data = [],
        array $connection = [],
        ?string $scope = null
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();
        $request_url = $connection['url'] . '/api/v1/' . ltrim($uri, '/');

        try {
            $request = self::pendingRequest($connection, $scope, 'DELETE', $request_url)->delete(
                url: $request_url,
                data: $data
            );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                uri: ltrim($uri, '/')
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'delete',
            url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
            response: $response,
            connection: $connection
        );

        return $response;
    }

    /**
     * Create a pending HTTP request with the headers for a single Okta API request
     *
     * When the OAuth access token is DPoP-bound, each request carries a DPoP proof that is bound to its HTTP method
     * and URL. If Okta responds with a `use_dpop_nonce` challenge, the request is retried once with a new proof that
     * includes the server-provided nonce. All other unsuccessful responses are returned without a retry so they are
     * handled by parseApiResponse() and throwExceptionIfEnabled() as usual.
     *
     * Each response is checked by forgetRejectedAccessToken() so that an OAuth access token rejected by Okta is
     * removed from the cache.
     *
     * @param  array<string, mixed>  $connection   The validated connection array
     * @param  string|null           $scope        The OAuth scope string. Required for OAuth, ignored for SSWS tokens.
     * @param  string                $http_method  The HTTP method that will be sent (ex. `GET`, `POST`)
     * @param  string                $url          The full request URL
     */
    private static function pendingRequest(
        array $connection,
        ?string $scope,
        string $http_method,
        string $url
    ): PendingRequest {
        return Http::withHeaders(self::getRequestHeaders($connection, $scope, $http_method, $url))
            ->withResponseMiddleware(function (ResponseInterface $response) use ($connection, $scope): ResponseInterface {
                self::forgetRejectedAccessToken($connection, $scope, new Response($response));

                return $response;
            })
            ->retry(
                times: 2,
                sleepMilliseconds: 0,
                when: function (Throwable $exception, PendingRequest $request) use ($connection, $scope, $http_method, $url): bool {
                    $dpop_nonce = $exception instanceof RequestException
                        ? DpopProof::nonceChallenge($exception->response)
                        : null;

                    if ($dpop_nonce === null) {
                        return false;
                    }

                    // replaceHeaders() is required since withHeaders() merges recursively and would send both proofs
                    $request->replaceHeaders(self::getRequestHeaders($connection, $scope, $http_method, $url, $dpop_nonce));

                    return true;
                },
                throw: false
            );
    }

    /**
     * Remove the cached OAuth access token if Okta rejected it
     *
     * A `401` response means that Okta did not accept the access token, such as when it was revoked or the service
     * app's granted scopes changed after it was minted. The token is removed from the cache so that the next request
     * mints a new one instead of being rejected for the rest of the cache lifetime. A `use_dpop_nonce` challenge is
     * also a `401` response, but the token is still valid and the request is retried by pendingRequest(). An SSWS
     * token is not cached and is ignored.
     *
     * @param  array<string, mixed>  $connection  The validated connection array
     * @param  string|null           $scope       The OAuth scope string that the access token was minted for
     * @param  Response              $response    The response to a single request attempt
     */
    private static function forgetRejectedAccessToken(array $connection, ?string $scope, Response $response): void
    {
        if ($response->status() !== 401 || empty($connection['client_id']) || DpopProof::nonceChallenge($response) !== null) {
            return;
        }

        ApiToken::forget(scope: $scope, connection: $connection);
    }

    /**
     * Set the request headers for the Okta API request
     *
     * @param  array        $connection   An array with `url` and either `token` or `client_id` and private key.
     * @param  string|null  $scope        The OAuth scope string. Required for OAuth, ignored for SSWS tokens.
     * @param  string       $http_method  The HTTP method that will be sent, used for the DPoP proof
     * @param  string       $url          The full request URL, used for the DPoP proof
     * @param  string|null  $dpop_nonce   (optional) The `DPoP-Nonce` value from a `use_dpop_nonce` challenge
     */
    private static function getRequestHeaders(
        array $connection,
        ?string $scope,
        string $http_method,
        string $url,
        ?string $dpop_nonce = null
    ): array {
        $composer_array = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $package_name = Str::title($composer_array['name']);

        // Use an OAuth 2.0 access token when an OAuth service app (client_id) is configured, otherwise fall back to
        // the legacy SSWS API token. The access token (and the DPoP proof when enabled) is minted by ApiToken.
        $authorization_headers = ! empty($connection['client_id'])
            ? ApiToken::authorizationHeaders(
                scope: $scope,
                connection: $connection,
                http_method: $http_method,
                http_url: $url,
                dpop_nonce: $dpop_nonce
            )
            : ['Authorization' => 'SSWS ' . $connection['token']];

        return [
            ...$authorization_headers,
            'User-Agent' => implode(' ', [
                $package_name,
                'boldlygrow/okta-api-client',
                'Laravel/' . app()->version(),
                'PHP/' . phpversion(),
            ]),
        ];
    }

    /**
     * Convert API Response Headers to Array
     *
     * This method is called from the parseApiResponse method to prettify the Guzzle Headers that are an array with
     * nested array for each value, and converts the single array values into strings and converts to an object for
     * easier and consistent accessibility with the parseApiResponse format.
     *
     * @param  array  $header_response
     *                                  [
     *                                  "Date" => array:1 [
     *                                  0 => "Sun, 30 Jan 2022 01:18:14 GMT"
     *                                  ]
     *                                  "Content-Type" => array:1 [
     *                                  0 => "application/json"
     *                                  ]
     *                                  "Transfer-Encoding" => array:1 [
     *                                  0 => "chunked"
     *                                  ]
     *                                  "Connection" => array:1 [
     *                                  0 => "keep-alive"
     *                                  ]
     *                                  "Server" => array:1 [
     *                                  0 => "nginx"
     *                                  ]
     *                                  // ...
     *                                  ]
     *
     * @return array
     *               [
     *               "Date" => "Sun, 30 Jan 2022 01:11:44 GMT",
     *               "Content-Type" => "application/json",
     *               "Transfer-Encoding" => "chunked",
     *               "Connection" => "keep-alive",
     *               "Server" => "nginx",
     *               "Public-Key-Pins-Report-Only" => (truncated),
     *               "Vary" => "Accept-Encoding",
     *               "x-okta-request-id" => "A1b2C3D4e5@f6G7H8I9j0k1L2M3",
     *               "x-xss-protection" => "0",
     *               "p3p" => "CP="HONK"",
     *               "x-rate-limit-limit" => "1000",
     *               "x-rate-limit-remaining" => "998",
     *               "x-rate-limit-reset" => "1643505155",
     *               "cache-control" => "no-cache, no-store",
     *               "pragma" => "no-cache",
     *               "expires" => "0",
     *               "content-security-policy" => (truncated),
     *               "expect-ct" => "report-uri="https://oktaexpectct.report-uri.com/r/t/ct/reportOnly", max-age=0",
     *               "x-content-type-options" => "nosniff",
     *               "Strict-Transport-Security" => "max-age=315360000; includeSubDomains",
     *               "set-cookie" => (truncated)
     *               ]
     */
    private static function convertHeadersToArray(array $header_response): array
    {
        return collect($header_response)->transform(function ($item) {
            if (is_array($item)) {
                return count($item) > 1 ? $item : $item[0];
            } else {
                return $item;
            }
        })->toArray();
    }

    /**
     * Check if the responses uses pagination and contains multiple pages
     *
     * If a 'link' header exists, then there is another page to loop
     * <https://mycompany.okta.com/api/v1/apps?after=0oa1ab2c3D4E5F6G7h8i&limit=50>; rel="next"
     *
     * @param  array  $headers  API response headers from Okta request or parsed response.
     *
     * @return bool True if the response requires multiple pages, false if response is a single page
     */
    private static function checkForPagination(array $headers): bool
    {
        if (array_key_exists('link', $headers) && is_array($headers['link'])) {
            foreach ($headers['link'] as $link_url) {
                if (Str::contains($link_url, 'next')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse the header array for the paginated URL that contains `next`.
     *
     * Okta uses a cursor pagination instead of page navigation. If a 'link' header exists, then there is another page
     * <https://mycompany.okta.com/api/v1/apps?after=0oa1ab2c3D4E5F6G7h8i&limit=50>; rel="next"
     *
     * @link https://developer.okta.com/docs/reference/core-okta-api/#pagination
     *
     * @param  array  $headers  API response headers from Okta request or parsed response.
     *
     * @return ?string URL string or null if not found
     */
    private static function generateNextPaginatedResultUrl(array $headers): ?string
    {
        if (array_key_exists('link', $headers)) {
            foreach ($headers['link'] as $link_key => $link_url) {
                if (Str::contains($link_url, 'next')) {
                    // Remove the '<' and '>; rel="next"' that is around the next api_url
                    // Before: <https://mycompany.okta.com/api/v1/apps?after=0oa1ab2c3D4E5F6G7h8i&limit=50>; rel="next"
                    // After: https://mycompany.okta.com/api/v1/apps?after=0oa1ab2c3D4E5F6G7h8i&limit=50
                    $url = Str::remove('<', $headers['link'][$link_key]);
                    $url = Str::remove('>; rel="next"', $url);

                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Helper function used to get Okta API results that require pagination.
     *
     * @link https://developer.okta.com/docs/reference/core-okta-api/#pagination
     *
     * @param  array        $connection     An array with `url` and `token`.
     * @param  string       $paginated_url  The paginated URL generated in the get() method
     * @param  array        $records        An array of records from the first page to append to paginated results
     * @param  string|null  $scope          (optional) The OAuth scope string from the originating get() request
     *
     * @return array An array of the response objects for each page combined.
     */
    private static function getPaginatedResults(
        array $connection,
        string $paginated_url,
        array $records = [],
        ?string $scope = null
    ): array {
        do {
            $event_ms = now();

            $request = self::pendingRequest($connection, $scope, 'GET', $paginated_url)->get(
                url: $paginated_url
            );

            $response = self::parseApiResponse($request);
            self::logResponse(
                event_ms: $event_ms,
                method: __METHOD__,
                url: $paginated_url,
                response: $response
            );
            self::throwExceptionIfEnabled(
                method: 'get|paginated',
                url: $paginated_url,
                response: $response,
                connection: $connection
            );

            $records[] = $response->data;

            if (self::checkForPagination($response->headers)) {
                $paginated_url = self::generateNextPaginatedResultUrl($response->headers);
            } else {
                $paginated_url = null;
            }
        } while ($paginated_url != null);

        return collect($records)->flatten(1)->toArray();
    }

    /**
     * Parse the API response and return custom formatted response for consistency
     *
     * @link https://laravel.com/docs/10.x/http-client#making-requests
     *
     * @param  object  $response  Response object from API results
     *
     * @return object
     *                {
     *                +"data": {
     *                +"id": 12345678
     *                +"name": "Dade Murphy"
     *                +"username": "z3r0c00l"
     *                +"state": "active"
     *                },
     *                +"headers": [
     *                "Date" => "Fri, 12 Nov 2021 20:13:55 GMT",
     *                "Content-Type" => "application/json",
     *                "Content-Length" => "1623",
     *                "Connection" => "keep-alive"
     *                ],
     *                +"status": {
     *                +"code": 200
     *                +"ok": true
     *                +"successful": true
     *                +"failed": false
     *                +"serverError": false
     *                +"clientError": false
     *                }
     *                }
     */
    public static function parseApiResponse(object $response): object
    {
        if (property_exists($response, 'paginated_results')) {
            return (object) [
                'data' => (object) $response->paginated_results,
                'headers' => self::convertHeadersToArray($response->headers),
                'status' => $response->status,
            ];
        } else {
            return (object) [
                'data' => $response->object(),
                'headers' => self::convertHeadersToArray($response->headers()),
                'status' => (object) [
                    'code' => $response->status(),
                    'ok' => $response->ok(),
                    'successful' => $response->successful(),
                    'failed' => $response->failed(),
                    'serverError' => $response->serverError(),
                    'clientError' => $response->clientError(),
                ],
            ];
        }
    }

    /**
     * Parse the reason for an unsuccessful API response into a human readable string
     *
     * The Okta API and the OAuth 2.0 token endpoints use different keys for error messages, and the
     * `errorSummary` for a validation failure is generic, so the reason for a failed request is
     * discarded when only checking `errorCode` and `errorSummary`.
     *
     * - `errorCode` and `errorSummary` are returned by the Okta API.
     *   Ex. `{"errorCode":"E0000007","errorSummary":"Not found: Resource not found: xyz (User)"}`
     * - `errorCauses` contains the reason for each attribute that failed validation. The
     *   `errorSummary` for these responses only names the object that failed (ex. `Api validation
     *   failed: profile`), so the causes are the only actionable part of the response.
     *   Ex. `{"errorCauses":[{"errorSummary":"firstName: The field cannot be left blank"}]}`
     * - `error` and `error_description` are returned by the OAuth 2.0 token endpoints.
     *   Ex. `{"error":"invalid_client","error_description":"The client secret is invalid."}`
     * - OAuth 2.0 resource server errors (ex. an invalid DPoP proof) have an empty body and return the
     *   `error` and `error_description` in the `WWW-Authenticate` header instead.
     *   Ex. `DPoP error="invalid_dpop_proof", error_description="'htu' claim in the DPoP proof JWT is invalid."`
     *
     * @see https://developer.okta.com/docs/reference/error-codes/
     *
     * @param  object  $response  The HTTP response formatted with $this->parseApiResponse()
     *
     * @return ?string The reason for the error or null if the response body and WWW-Authenticate header are empty
     *                 Ex. `E0000007 Not found: Resource not found: xyz (User)`
     *                 Ex. `E0000001 Api validation failed: profile (Causes) firstName: The field cannot be left blank`
     *                 Ex. `invalid_dpop_proof 'htu' claim in the DPoP proof JWT is invalid.`
     */
    private static function parseErrorMessage(object $response): ?string
    {
        $data = $response->data ?? null;

        // An empty body (ex. a 204 No Content or a non-JSON body that could not be decoded) has no reason in the
        // body, although an OAuth 2.0 resource server error has the reason in the WWW-Authenticate header.
        if (blank($data) || ((is_object($data) || is_array($data)) && blank((array) $data))) {
            return self::parseAuthenticateHeaderError((array) ($response->headers ?? []));
        }

        $reason = [];

        // `errorCode` is returned by the Okta API. `error` is returned by the OAuth 2.0 token endpoints.
        foreach (['errorCode', 'error'] as $key) {
            $value = data_get($data, $key);

            if (! blank($value) && is_scalar($value)) {
                $reason[] = trim((string) $value);
                break;
            }
        }

        // `errorSummary` is returned by the Okta API. `error_description` is returned by the OAuth
        // 2.0 token endpoints.
        foreach (['errorSummary', 'error_description'] as $key) {
            $value = data_get($data, $key);

            if (! blank($value)) {
                $reason[] = self::flattenErrorMessage($value);
                break;
            }
        }

        $causes = data_get($data, 'errorCauses');

        if (! blank($causes)) {
            $reason[] = '(Causes) ' . self::flattenErrorMessage(
                collect((array) $causes)->map(fn ($cause) => data_get($cause, 'errorSummary') ?? $cause)->all()
            );
        }

        if (! empty($reason)) {
            return implode(' ', $reason);
        }

        // The response body does not use a known error key (ex. an HTML error page from a proxy or
        // an undocumented response), so the entire body is returned to avoid discarding the reason.
        return Str::limit(is_scalar($data) ? (string) $data : (string) json_encode($data), 1000);
    }

    /**
     * Parse the OAuth 2.0 error from the WWW-Authenticate response header
     *
     * @link https://datatracker.ietf.org/doc/html/rfc6750#section-3
     *
     * @param  array<string, mixed>  $headers  The headers array formatted with $this->convertHeadersToArray()
     *
     * @return ?string The `error` and `error_description` or null if the header has no error
     *                 Ex. `invalid_dpop_proof The DPoP proof JWT has already been used.`
     */
    private static function parseAuthenticateHeaderError(array $headers): ?string
    {
        $header = collect($headers)->first(fn ($value, $key) => strcasecmp((string) $key, 'WWW-Authenticate') === 0);

        if (! is_string($header)) {
            return null;
        }

        preg_match_all('/\b(error|error_description)="([^"]*)"/', $header, $matches, PREG_SET_ORDER);

        return collect($matches)->pluck(2)->filter()->implode(' ') ?: null;
    }

    /**
     * Flatten a nested error message into a single line string
     *
     * @param  mixed  $value  A string, array, or object from an error response body
     *
     * @return string Ex. `firstName: The field cannot be left blank, email: Does not match required pattern`
     */
    private static function flattenErrorMessage(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        $messages = [];

        foreach ((array) $value as $key => $item) {
            $item = self::flattenErrorMessage($item);

            $messages[] = is_string($key) ? $key . ': ' . $item : $item;
        }

        return implode(', ', $messages);
    }

    /**
     * Handle Okta API Exception
     *
     * @see https://developer.okta.com/docs/reference/error-codes/
     *
     * @param  RequestException  $exception  An instance of the exception
     * @param  string            $method     The upstream method that invoked this method for traceability
     *                                       Ex. __METHOD__
     * @param  string            $uri        HTTP Request URI
     *
     * @return object
     *                {
     *                +"error": {
     *                +"code": "<string>"
     *                +"message": "<string>"
     *                +"method": "<string>"
     *                +"uri": "<string>"
     *                }
     *                +"status": {
     *                +"code": 400
     *                +"ok": false
     *                +"successful": false
     *                +"failed": true
     *                +"serverError": false
     *                +"clientError": true
     *                }
     */
    public static function handleException(
        RequestException $exception,
        $method,
        $uri
    ): object {
        AuditLog::create(
            errors: [
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTrace(),
            ],
            event_type: 'okta.api.' . explode('::', $method)[1] . '.error.http.exception',
            level: 'error',
            message: 'HTTP Response Exception',
            metadata: [
                'uri' => ltrim($uri, '/'),
            ],
            method: $method,
            transaction: true
        );

        return (object) [
            'error' => (object) [
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'method' => $method,
                'uri' => ltrim($uri, '/'),
            ],
            'status' => (object) [
                'code' => $exception->getCode(),
                'ok' => false,
                'successful' => false,
                'failed' => true,
                'serverError' => true,
                'clientError' => false,
            ],
        ];
    }

    /**
     * Create a log entry for an API call
     *
     * This method is called from other methods and create log entry and throw exception
     *
     * @param  string  $method    The upstream method that invoked this method for traceability
     *                            Ex. __METHOD__
     * @param  string  $url       The URL of the API call including the concatenated base URL and URI
     * @param  object  $response  The raw unformatted HTTP client response
     * @param  Carbon  $event_ms  A process start timestamp used to calculate duration in ms for logs
     */
    private static function logResponse(
        string $method,
        string $url,
        object $response,
        ?Carbon $event_ms = null
    ): void {
        $log_type = [
            200 => ['event_type' => 'success', 'level' => 'debug'],
            201 => ['event_type' => 'success', 'level' => 'debug'],
            202 => ['event_type' => 'success', 'level' => 'debug'],
            204 => ['event_type' => 'success', 'level' => 'debug'],
            400 => ['event_type' => 'warning.bad-request', 'level' => 'warning'],
            401 => ['event_type' => 'error.unauthorized', 'level' => 'error'],
            403 => ['event_type' => 'error.forbidden', 'level' => 'error'],
            404 => ['event_type' => 'warning.not-found', 'level' => 'warning'],
            405 => ['event_type' => 'error.method-not-allowed', 'level' => 'error'],
            412 => ['event_type' => 'error.precondition-failed', 'level' => 'error'],
            422 => ['event_type' => 'error.unprocessable', 'level' => 'error'],
            429 => ['event_type' => 'critical.rate-limit', 'level' => 'critical'],
            500 => ['event_type' => 'critical.server-error', 'level' => 'critical'],
            501 => ['event_type' => 'error.not-implemented', 'level' => 'error'],
            503 => ['event_type' => 'critical.server-unavailable', 'level' => 'critical'],
        ];

        $errors = [];
        if (isset($response->data->errorCode)) {
            $errors['error_code'] = $response->data->errorCode;
        }
        if ($response->status->failed && ($error_message = self::parseErrorMessage($response)) !== null) {
            $errors['error_message'] = $error_message;
        }
        if ($response->status->failed) {
            $errors['status_code'] = $response->status->code;
        }

        $message = 'Success';
        if ($response->status->clientError) {
            $message = 'Client Error';
        }
        if ($response->status->serverError) {
            $message = 'Server Error';
        }

        $count_records = null;
        if ($response->status->ok && is_countable($response->data)) {
            $count_records = count($response->data);
        }

        $event_ms_per_record = null;
        if ($event_ms && $count_records && $count_records > 1) {
            $event_ms_per_record = (int) ($event_ms->diffInMilliseconds() / $count_records);
        }

        AuditLog::create(
            count_records: $count_records,
            errors: $errors,
            event_ms: $event_ms,
            event_ms_per_record: $event_ms_per_record,
            event_type: implode('.', [
                'okta',
                'api',
                explode('::', $method)[1],
                $log_type[$response->status->code]['event_type'],
            ]),
            level: $log_type[$response->status->code]['level'],
            message: $message,
            metadata: [
                'okta_request_id' => $response->headers['x-okta-request-id'] ?? null,
                'rate_limit_remaining' => $response->headers['x-rate-limit-remaining'] ?? null,
                'url' => $url,
            ],
            method: $method,
            transaction: false
        );

        if (array_key_exists('x-rate-limit-remaining', $response->headers)) {
            self::checkIfRateLimitApproaching($method, $url, $response);
            self::checkIfRateLimitExceeded($method, $url, $response);
        }
    }

    /**
     * Throw an exception for a 4xx or 5xx response for an API call
     *
     * This method checks whether the .env variable or config value for `OKTA_API_EXCEPTIONS=true`
     *
     * @param  string                $method      The lowercase name of the method that calls this function (ex. `get`)
     * @param  string                $url         The URL of the API call including the concatenated base URL and URI
     * @param  object                $response    The HTTP response formatted with $this->parseApiResponse()
     * @param  array<string, mixed>  $connection  (optional) The validated connection array used for the request,
     *                                            which determines the OAuth or SSWS wording of a 401 error. If not
     *                                            set, the `config('okta-api-client')` array is used.
     *
     * @throws BadRequestException
     * @throws ConflictException
     * @throws ForbiddenException
     * @throws MethodNotAllowedException
     * @throws NotFoundException
     * @throws PreconditionFailedException
     * @throws RateLimitException
     * @throws ServerErrorException
     * @throws ServiceUnavailableException
     * @throws UnauthorizedException
     * @throws UnprocessableException
     */
    protected static function throwExceptionIfEnabled(
        string $method,
        string $url,
        object $response,
        array $connection = []
    ): void {
        if (config('okta-api-client.exceptions') == true) {
            $error_message = self::parseErrorMessage($response);

            // The URL is kept exactly as it was requested so that it can be reproduced verbatim. A
            // percent encoded path or query string is hard to read, so a decoded copy is appended
            // when it differs. The decoded URL is for display only and is never used on its own,
            // because decoding a `%2F` produces a URL that the API routes to a different endpoint.
            $decoded_url = urldecode($url);

            $message = implode(' ', array_filter([
                Str::upper($method),
                $response->status->code,
                $url,
                $error_message ? '(Reason) ' . $error_message : null,
                $decoded_url !== $url ? '(Decoded) ' . $decoded_url : null,
            ]));

            switch ($response->status->code) {
                case 400:
                    throw new BadRequestException($message);
                case 401:
                    // The authentication mode is determined by the connection that was used for the request, so an
                    // SSWS connection array is not reported as OAuth when the config has a client_id (and vice versa).
                    $client_id = empty($connection)
                        ? config('okta-api-client.client_id')
                        : ($connection['client_id'] ?? null);

                    $message = ! empty($client_id)
                        ? implode(' ', [
                            'The OAuth access token was rejected.',
                            '(Reason) It may be expired, or the requested scopes are not granted to the service app.',
                            '(Solution) Verify the service app\'s granted Okta API scopes and that its public key is',
                            'registered in the Okta app JWKSet.',
                        ])
                        : implode(' ', [
                            'The SSWS API token was rejected.',
                            '(Reason) This usually happens if it does not exist or expired after 30 days of inactivity.',
                            '(Solution) Please generate a new API token and update the `OKTA_API_TOKEN` variable in your',
                            '`.env` file, or the `token` in your connection array.',
                        ]);
                    throw new UnauthorizedException($message);
                case 403:
                    throw new ForbiddenException($message);
                case 404:
                    throw new NotFoundException($message);
                case 405:
                    throw new MethodNotAllowedException($message);
                case 409:
                    throw new ConflictException($message);
                case 412:
                    throw new PreconditionFailedException($message);
                case 422:
                    throw new UnprocessableException($message);
                case 429:
                    throw new RateLimitException($message);
                case 500:
                    throw new ServerErrorException($message);
                case 503:
                    throw new ServiceUnavailableException($message);
            }
        }
    }

    /**
     * Create a warning log entry and delay the API request by 10 seconds
     * if the rate limit remaining is less than 20 percent
     *
     * @param  string  $method    The upstream method that invoked this method for traceability
     *                            Ex. __METHOD__
     * @param  string  $url       The URL of the API call including the concatenated base URL and URI
     * @param  object  $response  The HTTP response formatted with $this->parseApiResponse()
     */
    private static function checkIfRateLimitApproaching(
        string $method,
        string $url,
        object $response
    ): void {
        if (array_key_exists('x-rate-limit-remaining', $response->headers)) {
            $remaining = (int) $response->headers['x-rate-limit-remaining'];
            $limit = (int) $response->headers['x-rate-limit-limit'];
            $percent_remaining = round(($remaining / $limit) * 100);

            if ($percent_remaining <= 20) {
                AuditLog::create(
                    event_type: 'okta.api.rate-limit.approaching',
                    level: 'critical',
                    message: implode(' ', [
                        'Rate Limit Approaching (' . $percent_remaining . '% Remaining).',
                        'Sleeping for 10 seconds between requests to let the API catch a breath.',
                    ]),
                    metadata: [
                        'okta_request_id' => $response->headers['x-okta-request-id'] ?? null,
                        'okta_rate_limit_limit' => $response->headers['x-rate-limit-limit'] ?? null,
                        'okta_rate_limit_percent' => $percent_remaining,
                        'okta_rate_limit_remaining' => $response->headers['x-rate-limit-remaining'] ?? null,
                        'url' => $url,
                    ],
                    method: $method,
                    transaction: false
                );

                sleep(10);
            }
        }
    }

    /**
     * Create an error log entry for an API call if the rate limit remaining is equal to zero (0) or one (1),
     * indicating that this is the last request that will be successful.
     *
     * @param  string  $method    The upstream method that invoked this method for traceability
     *                            Ex. __METHOD__
     * @param  string  $url       The URL of the API call including the concatenated base URL and URI
     * @param  object  $response  The HTTP response formatted with $this->parseApiResponse()
     */
    private static function checkIfRateLimitExceeded(
        string $method,
        string $url,
        object $response
    ): void {
        if (array_key_exists('x-rate-limit-remaining', $response->headers)) {
            if ($response->headers['x-rate-limit-remaining'] <= 1) {
                AuditLog::create(
                    event_type: 'okta.api.rate-limit.exceeded',
                    level: 'critical',
                    message: implode(' ', [
                        'Rate Limit Exceeded.',
                        'This request should be refactored so we do not cause the API any further harm.',
                    ]),
                    metadata: [
                        'okta_request_id' => $response->headers['x-okta-request-id'] ?? null,
                        'okta_rate_limit_limit' => $response->headers['x-rate-limit-limit'] ?? null,
                        'okta_rate_limit_remaining' => $response->headers['x-rate-limit-remaining'] ?? null,
                        'url' => $url,
                    ],
                    method: $method,
                    transaction: true
                );

                throw new RateLimitException('Okta API rate limit exceeded. See logs for details.');
            }
        }
    }
}
