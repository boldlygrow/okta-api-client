<?php

/**
 * @copyright Jefferson Martin
 * @license MIT <https://spdx.org/licenses/MIT.html>
 *
 * @link https://github.com/boldlygrow/okta-api-client
 */

namespace BoldlyGrow\Okta;

use BoldlyGrow\Audit\Log;
use BoldlyGrow\Okta\Exceptions\BadRequestException;
use BoldlyGrow\Okta\Exceptions\ConfigurationException;
use BoldlyGrow\Okta\Exceptions\ConflictException;
use BoldlyGrow\Okta\Exceptions\ForbiddenException;
use BoldlyGrow\Okta\Exceptions\MethodNotAllowedException;
use BoldlyGrow\Okta\Exceptions\NotFoundException;
use BoldlyGrow\Okta\Exceptions\PreconditionFailedException;
use BoldlyGrow\Okta\Exceptions\RateLimitException;
use BoldlyGrow\Okta\Exceptions\ServerErrorException;
use BoldlyGrow\Okta\Exceptions\UnauthorizedException;
use BoldlyGrow\Okta\Exceptions\UnprocessableException;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
     * @param  array  $connection  (optional) An array with `url` and `token`. If not set, the
     *                             `config('okta-api-client')` array will be used that uses the
     *                             OKTA_API_* variables from your .env file.
     *
     * @throws ConfigurationException
     */
    public static function testConnection(array $connection = []): bool
    {
        $response = self::get(
            uri: config('okta-api-client.test_uri'),
            connection: $connection
        );

        Log::create(
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
            'token' => 'required_without:client_id|alpha_dash|size:42',
            'client_id' => 'required_without:token|string',
        ]);

        if ($validator->fails()) {
            Log::create(
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
        // (client_id, key_source, key_secret, scopes, key_id, etc.) survive to getRequestHeaders()
        // and ApiToken::create(). $validator->validated() alone would drop them.
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
     * @param  string  $uri         The URI with or without leading slash after `/api/v1/`
     * @param  array   $data        (optional) Query data to apply to GET request
     * @param  array   $connection  (optional) An array with `url` and `token`. If not set, the
     *                              `config('okta-api-client')` array will be used that uses the
     *                              OKTA_API_* variables from your .env file.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function get(
        string $uri,
        array $data = [],
        array $connection = [],
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();

        try {
            $request = Http::withHeaders(self::getRequestHeaders($connection))->get(
                url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
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
            response: $response
        );

        // If the response is a paginated response
        if (self::checkForPagination($response->headers) == true) {
            Log::create(
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
                records: $response->data
            );

            // Unset property for original request body
            unset($response->body);

            // Parse API Response and convert to returnable object with expected format
            $response = self::parseApiResponse($response);

            $count_records = is_countable($response->data) ? count($response->data) : null;
            $duration_ms_per_record = $count_records ? (int) ($event_ms->diffInMilliseconds() / $count_records) : null;

            Log::create(
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
     * @param  string  $uri         The URI without leading slash after `/api/v1/`
     * @param  array   $data        (optional) Post Body array
     * @param  array   $connection  (optional) An array with `url` and `token`. If not set, the
     *                              `config('okta-api-client')` array will be used that uses the
     *                              OKTA_API_* variables from your .env file.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function post(
        string $uri,
        array $data = [],
        array $connection = []
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();

        try {
            $request = Http::withHeaders(self::getRequestHeaders($connection))->post(
                url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
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
            response: $response
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
     * @param  string  $uri         The URI without leading slash after `/api/v1/`
     * @param  array   $data        (optional) Optional request data to send with PUT request
     * @param  array   $connection  (optional) An array with `url` and `token`. If not set, the
     *                              `config('okta-api-client')` array will be used that uses the
     *                              OKTA_API_* variables from your .env file.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function patch(
        string $uri,
        array $data = [],
        array $connection = []
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();

        try {
            $request = Http::withHeaders(self::getRequestHeaders($connection))->post(
                url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
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
            response: $response
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
     * @param  string  $uri         The URI without leading slash after `/api/v1/`
     * @param  array   $data        (optional) Optional request data to send with PUT request
     * @param  array   $connection  (optional) An array with `url` and `token`. If not set, the
     *                              `config('okta-api-client')` array will be used that uses the
     *                              OKTA_API_* variables from your .env file.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function put(
        string $uri,
        array $data = [],
        array $connection = []
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();

        try {
            $request = Http::withHeaders(self::getRequestHeaders($connection))->put(
                url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
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
            response: $response
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
     * @param  string  $uri         The URI without leading slash after `/api/v1/`
     * @param  array   $data        Optional request data to send with DELETE request
     * @param  array   $connection  (optional) An array with `url` and `token`. If not set, the
     *                              `config('okta-api-client')` array will be used that uses the
     *                              OKTA_API_* variables from your .env file.
     *
     * @return object See parseApiResponse() method. The content and schema of the data array can
     *                be found in the API documentation for the endpoint.
     */
    public static function delete(
        string $uri,
        array $data = [],
        array $connection = []
    ): object {
        $connection = self::validateConnection($connection);
        $event_ms = now();

        try {
            $request = Http::withHeaders(self::getRequestHeaders($connection))->delete(
                url: $connection['url'] . '/api/v1/' . ltrim($uri, '/'),
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
            response: $response
        );

        return $response;
    }

    /**
     * Set the request headers for the Okta API request
     *
     * @param  array  $connection  An array with `url` and `token`.
     */
    private static function getRequestHeaders(array $connection): array
    {
        $composer_array = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $package_name = Str::title($composer_array['name']);

        // Use OAuth 2.0 Bearer token when an OAuth service app (client_id) is configured, otherwise fall back to
        // the legacy SSWS API token. The Bearer token is minted and cached by ApiToken.
        $authorization = ! empty($connection['client_id'])
            ? 'Bearer ' . ApiToken::create(connection: $connection)
            : 'SSWS ' . $connection['token'];

        return [
            'Authorization' => $authorization,
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
     * @param  array   $connection     An array with `url` and `token`.
     * @param  string  $paginated_url  The paginated URL generated in the get() method
     * @param  array   $records        An array of records from the first page to append to paginated results
     *
     * @return array An array of the response objects for each page combined.
     */
    private static function getPaginatedResults(
        array $connection,
        string $paginated_url,
        array $records = []
    ): array {
        do {
            $event_ms = now();

            $request = Http::withHeaders(self::getRequestHeaders($connection))->get(
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
                response: $response
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
        Log::create(
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
        if (isset($response->data->errorSummary)) {
            $errors['error_message'] = $response->data->errorSummary;
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

        Log::create(
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
     * @param  string  $method    The lowercase name of the method that calls this function (ex. `get`)
     * @param  string  $url       The URL of the API call including the concatenated base URL and URI
     * @param  object  $response  The HTTP response formatted with $this->parseApiResponse()
     *
     * @throws BadRequestException
     * @throws ConflictException
     * @throws ForbiddenException
     * @throws MethodNotAllowedException
     * @throws NotFoundException
     * @throws PreconditionFailedException
     * @throws RateLimitException
     * @throws ServerErrorException
     * @throws UnauthorizedException
     * @throws UnprocessableException
     */
    protected static function throwExceptionIfEnabled(
        string $method,
        string $url,
        object $response
    ): void {
        if (config('okta-api-client.exceptions') == true) {
            $message = implode(' ', [
                Str::upper($method),
                $response->status->code,
                $url,
                isset($response->data->errorCode) ? $response->data->errorCode : null,
                isset($response->data->errorSummary) ? $response->data->errorSummary : null,
            ]);

            switch ($response->status->code) {
                case 400:
                    throw new BadRequestException($message);
                case 401:
                    $message = ! empty(config('okta-api-client.client_id'))
                        ? implode(' ', [
                            'The OAuth access token was rejected.',
                            '(Reason) It may be expired, or the requested scopes are not granted to the service app.',
                            '(Solution) Verify the service app\'s granted Okta API scopes and that its public key is',
                            'registered in the Okta app JWKSet.',
                        ])
                        : implode(' ', [
                            'The `OKTA_API_TOKEN` has been configured but is invalid.',
                            '(Reason) This usually happens if it does not exist or expired after 30 days of inactivity.',
                            '(Solution) Please generate a new API Token and update the variable in your `.env` file.',
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
                    throw new ServerErrorException(json_encode($response->data));
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
                Log::create(
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
                Log::create(
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
