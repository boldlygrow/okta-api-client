<?php

/**
 * @copyright Jefferson Martin
 * @license MIT <https://spdx.org/licenses/MIT.html>
 *
 * @link https://github.com/boldlygrow/okta-api-client
 */

namespace BoldlyGrow\Okta;

use BoldlyGrow\AuditLog\AuditLog;
use BoldlyGrow\Okta\Exceptions\ConfigurationException;
use BoldlyGrow\Okta\Exceptions\ScopeException;
use BoldlyGrow\Okta\Exceptions\UnauthorizedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Okta API Authentication Token Generator (OAuth 2.0 for Okta / private_key_jwt)
 *
 * @author Jeff Martin
 *
 * This mints a short-lived scoped OAuth 2.0 access token for the Okta *management* API using the Client Credentials
 * grant with a `private_key_jwt` client assertion. This is the ONLY client authentication method Okta supports for
 * access tokens that carry Okta admin-management scopes (okta.users.read, okta.groups.read, okta.apps.read, etc.).
 *
 * Client ID + client secret only works against a *custom* authorization server for *custom* scopes and cannot read
 * Okta resources, so it is intentionally not supported here.
 *
 * @link https://developer.okta.com/docs/guides/implement-oauth-for-okta-serviceapp/main/
 *
 * PRIVATE KEY RESOLUTION
 * The RSA private key used to sign the client assertion is provided in one of two ways:
 *
 *   - `private_key`: an inline PEM string. This is the primary mechanism. Fetch the key from wherever you store it
 *     (a secrets manager, a vault, a mounted file) in your own application code and pass it in the connection array,
 *     or set the `OKTA_API_PRIVATE_KEY` value in the published config.
 *   - `private_key_path`: a filesystem path to a PEM file (a leading `~` is expanded), suitable for local
 *     development or a secret mounted as a file.
 *
 * This package intentionally does not integrate with any secrets manager. Retrieving the key is the responsibility
 * of the consuming application, which keeps this package independent of your storage choice and free of cloud SDK
 * dependencies.
 *
 * DPOP
 * When `dpop` is enabled (OKTA_API_DPOP), the token request carries a DPoP proof and Okta returns a DPoP-bound access
 * token (token type `DPoP`) that is only accepted with a proof signed by the same ephemeral key. This is required when
 * the Okta service app has "Require Demonstrating Proof of Possession (DPoP) header in token requests" enabled. See
 * the DpopProof class for the key lifecycle and nonce handling.
 *
 * CACHING
 * The token is requested once per unique (url + client_id + scopes + key + dpop) combination, encrypted along with
 * its token type and any DPoP private key, and cached for `token_cache_minutes` (OKTA_API_TOKEN_CACHE_MINUTES, 59 by
 * default). Okta fixes the access token lifetime at one hour. The cache key is built from stable identifiers only, so the private key is resolved (and any file read
 * happens) only on a cache miss. If Okta rejects the token with a `401` response, ApiClient removes it from the cache
 * with forget() so that the next request mints a new one.
 *
 * This is called from inside the ApiClient class and does not need to be instantiated separately in your code.
 */
class ApiToken
{
    // Standard parameters for the Client Credentials + private_key_jwt exchange with the Okta org auth server.
    public const AUTH_GRANT_TYPE = 'client_credentials';

    public const CLIENT_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    public const TOKEN_ENDPOINT_PATH = '/oauth2/v1/token';

    public const DEFAULT_ALGORITHM = 'RS256';

    public const JWT_TYPE = 'JWT';

    // Okta access tokens live 60 minutes, so a cached token must expire before then.
    public const DEFAULT_CACHE_MINUTES = 59;

    public const MAX_CACHE_MINUTES = 59;

    /**
     * Create (or return a cached) Okta API access token for the requested scopes.
     *
     * @param  string|null  $scope
     *                                   A required space-delimited OAuth scope string (ex. 'okta.users.read' or
     *                                   'okta.users.read okta.groups.read'). There is no config or environment default; a ScopeException
     *                                   is thrown if it is empty. Nullable only so the caller can forward a missing value and receive that
     *                                   exception rather than a type error.
     * @param  array        $connection  (optional)
     *                                   An array with `url`, `client_id`, `key_id`, either `private_key` or `private_key_path`, plus
     *                                   optional `dpop`. If not set, the `config('okta-api-client')` array is used.
     *
     * @throws ConfigurationException
     * @throws ScopeException
     * @throws UnauthorizedException
     */
    public static function create(?string $scope = null, array $connection = []): string
    {
        return self::resolveToken($scope, $connection)['access_token'];
    }

    /**
     * Get the authorization headers for a single Okta API request.
     *
     * A DPoP-bound access token (token type `DPoP`) is sent with the `DPoP` authorization scheme and a `DPoP` proof
     * header that is bound to the HTTP method, URL, and access token of this request. Any other access token is sent
     * with the `Bearer` authorization scheme.
     *
     * @param  string|null           $scope        A required space-delimited OAuth scope string. See create().
     * @param  array<string, mixed>  $connection   See create(). An empty array uses `config('okta-api-client')`.
     * @param  string                $http_method  The HTTP method that will be sent (ex. `GET`, `POST`)
     * @param  string                $http_url     The full URL of the request
     * @param  string|null           $dpop_nonce   (optional) The `DPoP-Nonce` value from a `use_dpop_nonce` challenge
     *
     * @return array<string, string>
     *
     * @throws ConfigurationException
     * @throws ScopeException
     * @throws UnauthorizedException
     */
    public static function authorizationHeaders(
        ?string $scope,
        array $connection,
        string $http_method,
        string $http_url,
        ?string $dpop_nonce = null,
    ): array {
        $token = self::resolveToken($scope, $connection);

        if ($token['dpop_private_key'] === null || strcasecmp($token['token_type'], 'DPoP') !== 0) {
            return ['Authorization' => 'Bearer ' . $token['access_token']];
        }

        return [
            'Authorization' => 'DPoP ' . $token['access_token'],
            'DPoP' => DpopProof::create(
                private_key: $token['dpop_private_key'],
                http_method: $http_method,
                http_url: $http_url,
                nonce: $dpop_nonce,
                access_token: $token['access_token']
            ),
        ];
    }

    /**
     * Get the cached access token for the requested scopes, or mint and cache a new one.
     *
     * @param  string|null           $scope       A required space-delimited OAuth scope string. See create().
     * @param  array<string, mixed>  $connection  See create(). An empty array uses `config('okta-api-client')`.
     *
     * @return array{access_token: string, token_type: string, dpop_private_key: string|null}
     *
     * @throws ConfigurationException
     * @throws ScopeException
     * @throws UnauthorizedException
     */
    private static function resolveToken(?string $scope, array $connection): array
    {
        $validated_connection = self::validateConnectionArray($connection);

        $scopes = self::resolveScopes($scope);

        $dpop = filter_var($validated_connection['dpop'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $encrypted_token = Cache::remember(
            key: self::cacheKey($validated_connection, $scopes, $dpop),
            ttl: self::cacheMinutes() * 60,
            callback: function () use ($validated_connection, $scopes, $dpop) {
                // Resolve the private key only on a cache miss.
                $private_key = self::getPrivateKeyContents($validated_connection);

                // The DPoP key is cached with the token since every request presenting the token is signed with it.
                $dpop_private_key = $dpop ? DpopProof::generatePrivateKey() : null;

                $api_token = self::sendTokenRequest(
                    url: $validated_connection['url'],
                    client_id: $validated_connection['client_id'],
                    key_id: $validated_connection['key_id'],
                    private_key: $private_key,
                    scopes: $scopes,
                    dpop_private_key: $dpop_private_key
                );

                return encrypt([
                    ...$api_token,
                    'dpop_private_key' => $dpop_private_key,
                ]);
            }
        );

        /** @var array{access_token: string, token_type: string, dpop_private_key: string|null} $token */
        $token = decrypt($encrypted_token);

        return $token;
    }

    /**
     * Remove the cached access token for the requested scopes so that the next request mints a new one.
     *
     * This is called by ApiClient when Okta rejects the access token with a `401` response, so a token that was
     * revoked or minted before the service app's granted scopes changed is not reused for the rest of its lifetime.
     *
     * @param  string|null           $scope       A required space-delimited OAuth scope string. See create().
     * @param  array<string, mixed>  $connection  See create(). An empty array uses `config('okta-api-client')`.
     *
     * @throws ConfigurationException
     * @throws ScopeException
     */
    public static function forget(?string $scope = null, array $connection = []): void
    {
        $validated_connection = self::validateConnectionArray($connection);

        Cache::forget(self::cacheKey(
            validated_connection: $validated_connection,
            scopes: self::resolveScopes($scope),
            dpop: filter_var($validated_connection['dpop'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ));
    }

    /**
     * Build the access token cache key from stable identifiers only, so the private key does not need to be read on a
     * cache hit.
     *
     * `key_ref` is the file path when using private_key_path, or a fingerprint of the inline key, so rotating the key
     * busts the cache. `dpop` is included so toggling it never reuses a token minted the other way, since a DPoP-bound
     * token cannot be presented as a Bearer token.
     *
     * @param  array<string, mixed>  $validated_connection  The connection array from validateConnectionArray()
     * @param  string                $scopes                The scope string from resolveScopes()
     * @param  bool                  $dpop                  Whether the access token is DPoP-bound
     */
    private static function cacheKey(array $validated_connection, string $scopes, bool $dpop): string
    {
        return 'okta-api-token-' . md5(json_encode([
            'url' => $validated_connection['url'],
            'client_id' => $validated_connection['client_id'],
            'scopes' => $scopes,
            'key_id' => $validated_connection['key_id'],
            'key_ref' => $validated_connection['private_key_path']
                ?? (isset($validated_connection['private_key'])
                    ? 'inline:' . md5((string) $validated_connection['private_key'])
                    : null),
            'dpop' => $dpop,
        ]));
    }

    /**
     * Get the number of minutes to cache an access token for from the `token_cache_minutes` config value.
     *
     * @throws ConfigurationException
     */
    private static function cacheMinutes(): int
    {
        $cache_minutes = filter_var(
            config('okta-api-client.token_cache_minutes', self::DEFAULT_CACHE_MINUTES),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => self::MAX_CACHE_MINUTES]]
        );

        if ($cache_minutes === false) {
            $reason = 'The token_cache_minutes (OKTA_API_TOKEN_CACHE_MINUTES) value must be a whole number between 1'
                . ' and ' . self::MAX_CACHE_MINUTES . ', since Okta access tokens expire after 60 minutes.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'okta.api.auth.validate.error.cache',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: true
            );

            throw new ConfigurationException(implode(' ', [
                'Okta API OAuth configuration validation error.',
                '(Reason) ' . $reason,
            ]));
        }

        return $cache_minutes;
    }

    /**
     * Validate that the required OAuth keys exist in the connection array.
     *
     *
     * @throws ConfigurationException
     */
    private static function validateConnectionArray(array $connection): array
    {
        $connection_config = ! empty($connection) ? $connection : config('okta-api-client');

        $validator = Validator::make(
            data: $connection_config,
            rules: [
                'url' => ['required', 'url:https'],
                'client_id' => ['required', 'string'],
                'key_id' => ['required', 'string'],
                'private_key' => ['nullable', 'string', 'required_without:private_key_path'],
                'private_key_path' => ['nullable', 'string', 'required_without:private_key'],
                'dpop' => ['nullable', 'boolean'],
            ],
            messages: [
                'key_id.required' => 'The key_id (OKTA_API_KEY_ID) is required. Set it to the Key ID (KID) of the'
                    . ' public key in the Public Keys table of the Okta service app.',
            ],
        );

        if ($validator->fails()) {
            AuditLog::create(
                errors: $validator->errors()->all(),
                event_type: 'okta.api.auth.validate.error',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: true
            );

            throw new ConfigurationException(implode(' ', [
                'Okta API OAuth configuration validation error.',
                'This occurred in ' . __METHOD__ . '.',
                '(Solution) ' . $validator->messages()->first(),
            ]));
        }

        // Return validated keys merged over the source config so nothing is silently dropped.
        return array_merge($connection_config, $validator->validated());
    }

    /**
     * Validate and normalize the per-call OAuth scope.
     *
     * The scope must be supplied as an argument on each request (there is no config or environment default). One or
     * more space-delimited scopes may be passed (ex. 'okta.users.read' or 'okta.users.read okta.groups.read'). This
     * is only reached when authenticating with OAuth; the SSWS token path never calls ApiToken and ignores scope.
     *
     *
     * @throws ScopeException
     */
    private static function resolveScopes(?string $scope): string
    {
        $scopes = trim((string) $scope);

        if ($scopes === '') {
            $reason = 'An OAuth scope is required but was not provided.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'okta.api.auth.validate.error.scopes',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: true
            );

            throw new ScopeException(implode(' ', [
                'Okta API OAuth error.',
                '(Reason) ' . $reason,
                '(Solution) Pass the `scope` argument on the request, for example',
                "ApiClient::get(uri: 'users', scope: 'okta.users.read').",
            ]));
        }

        return $scopes;
    }

    /**
     * Resolve the PEM-formatted private key from the inline value or a file path.
     *
     * Precedence: an inline `private_key` is used when set, otherwise `private_key_path` is read from disk. This is
     * called only on a token cache miss.
     *
     *
     * @throws ConfigurationException
     */
    private static function getPrivateKeyContents(array $connection): string
    {
        if (! empty($connection['private_key'])) {
            $private_key = (string) $connection['private_key'];
        } elseif (! empty($connection['private_key_path'])) {
            $path = self::expandHomeDirectory((string) $connection['private_key_path']);

            if (! is_readable($path)) {
                $reason = 'The private key file was not found or is not readable at (' . $path . ').';

                AuditLog::create(
                    errors: [$reason],
                    event_type: 'okta.api.auth.validate.error.key',
                    level: 'critical',
                    message: 'Error',
                    method: __METHOD__,
                    transaction: true
                );

                throw new ConfigurationException(implode(' ', [
                    'Okta API OAuth configuration validation error.',
                    '(Reason) ' . $reason,
                ]));
            }

            $private_key = (string) file_get_contents($path);
        } else {
            throw new ConfigurationException(implode(' ', [
                'Okta API OAuth configuration validation error.',
                '(Reason) No private key was provided.',
                '(Solution) Set `private_key` (an inline PEM string) or `private_key_path` (a PEM file path) in the',
                'connection array or config.',
            ]));
        }

        // Support keys stored on a single line with escaped newlines (ex. a local .env value).
        $private_key = str_replace('\n', "\n", $private_key);

        if (empty(trim($private_key)) || openssl_pkey_get_private($private_key) === false) {
            $reason = 'The resolved private key is empty or is not a valid PEM-formatted private key.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'okta.api.auth.validate.error.key',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: true
            );

            throw new ConfigurationException(implode(' ', [
                'Okta API OAuth configuration validation error.',
                '(Reason) ' . $reason,
            ]));
        }

        return $private_key;
    }

    /**
     * Expand a leading ~ to the current user's home directory.
     */
    private static function expandHomeDirectory(string $path): string
    {
        if (str_starts_with($path, '~/') || $path === '~') {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');

            return $home . substr($path, 1);
        }

        return $path;
    }

    /**
     * Build and sign the JWT client assertion used to authenticate to the /token endpoint.
     *
     * @link https://developer.okta.com/docs/guides/implement-oauth-for-okta-serviceapp/main/#create-and-sign-the-jwt
     *
     * @param  string  $url
     *                               The Okta org base URL (ex. https://your-org.okta.com)
     * @param  string  $client_id
     *                               The service app client_id (used for both `iss` and `sub`)
     * @param  string  $key_id
     *                               The `kid` of the registered public key. Included in the JWT header so Okta can select the
     *                               correct verification key from the app's JWKSet. Okta rejects an assertion without it.
     * @param  string  $private_key
     *                               PEM-formatted RSA private key
     *
     * @throws ConfigurationException
     */
    private static function createClientAssertion(
        string $url,
        string $client_id,
        string $key_id,
        string $private_key
    ): string {
        $header = [
            'alg' => self::DEFAULT_ALGORITHM,
            'typ' => self::JWT_TYPE,
            'kid' => $key_id,
        ];

        $now = time();

        $claims = [
            'iss' => $client_id,
            'sub' => $client_id,
            'aud' => rtrim($url, '/') . self::TOKEN_ENDPOINT_PATH,
            'iat' => $now,
            'exp' => $now + 300,
            'jti' => (string) Str::uuid(),
        ];

        $signing_input = self::base64UrlEncode((string) json_encode($header))
            . '.' . self::base64UrlEncode((string) json_encode($claims));

        $signature = self::sign($signing_input, $private_key);

        return $signing_input . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Create the raw binary signature over the JWT signing input.
     *
     * Uses a dedicated $signature output variable (rather than reusing the private key variable as the
     * openssl_sign() output reference) so the intent is explicit and safe to refactor.
     *
     *
     * @throws ConfigurationException
     */
    private static function sign(string $signing_input, string $private_key): string
    {
        $key_resource = openssl_pkey_get_private($private_key);

        if ($key_resource === false) {
            throw new ConfigurationException('Okta API OAuth error. (Reason) The private key could not be parsed.');
        }

        $signature = '';

        $signed = openssl_sign(
            data: $signing_input,
            signature: $signature,
            private_key: $key_resource,
            algorithm: self::algorithmConstant(self::DEFAULT_ALGORITHM)
        );

        if ($signed === false || $signature === '') {
            $reason = 'The JWT client assertion could not be signed with the provided private key.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'okta.api.auth.error.signature',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: true
            );

            throw new ConfigurationException('Okta API OAuth error. (Reason) ' . $reason);
        }

        return $signature;
    }

    /**
     * Map a JWT `alg` value to its OpenSSL algorithm constant.
     *
     * RSA algorithms are supported directly by openssl_sign(). EC algorithms (ES256/384/512) are intentionally not
     * mapped here because openssl_sign() emits a DER-encoded ECDSA signature that must be converted to the raw
     * R||S concatenation the JWS spec requires. Use an RSA key, or extend this method with that conversion.
     *
     *
     * @throws ConfigurationException
     */
    private static function algorithmConstant(string $algorithm): int
    {
        return match ($algorithm) {
            'RS256' => OPENSSL_ALGO_SHA256,
            'RS384' => OPENSSL_ALGO_SHA384,
            'RS512' => OPENSSL_ALGO_SHA512,
            default => throw new ConfigurationException(
                'Okta API OAuth error. (Reason) Unsupported signing algorithm (' . $algorithm . ').'
            ),
        };
    }

    /**
     * Base64url encode without padding, per the JWS spec.
     */
    private static function base64UrlEncode(string $input): string
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    /**
     * Exchange the signed client assertion for a scoped access token at the Okta org /token endpoint.
     *
     * @link https://developer.okta.com/docs/guides/implement-oauth-for-okta-serviceapp/main/#get-an-access-token
     *
     * @param  string       $url               The Okta org base URL (ex. https://your-org.okta.com)
     * @param  string       $client_id         The service app client_id
     * @param  string       $key_id            The `kid` of the registered public key
     * @param  string       $private_key       PEM-formatted RSA private key used to sign the client assertion
     * @param  string       $scopes            The space-delimited OAuth scope string
     * @param  string|null  $dpop_private_key  PEM-formatted ephemeral DPoP key, or null when DPoP is disabled
     *
     * @return array{access_token: string, token_type: string}
     *
     * @throws ConfigurationException
     * @throws UnauthorizedException
     */
    private static function sendTokenRequest(
        string $url,
        string $client_id,
        string $key_id,
        string $private_key,
        string $scopes,
        ?string $dpop_private_key
    ): array {
        $token_url = rtrim($url, '/') . self::TOKEN_ENDPOINT_PATH;

        // A new client assertion (with a new jti) is signed for each attempt since a retry must not replay one
        $send = fn (?string $dpop_nonce): Response => Http::asForm()
            ->withHeaders($dpop_private_key !== null ? [
                'DPoP' => DpopProof::create(
                    private_key: $dpop_private_key,
                    http_method: 'POST',
                    http_url: $token_url,
                    nonce: $dpop_nonce
                ),
            ] : [])
            ->post(
                url: $token_url,
                data: [
                    'grant_type' => self::AUTH_GRANT_TYPE,
                    'scope' => $scopes,
                    'client_assertion_type' => self::CLIENT_ASSERTION_TYPE,
                    'client_assertion' => self::createClientAssertion(
                        url: $url,
                        client_id: $client_id,
                        key_id: $key_id,
                        private_key: $private_key
                    ),
                ]
            );

        $response = $send(null);

        // Okta requires a server-provided nonce in the DPoP proof and issues it with a `use_dpop_nonce` challenge
        $dpop_nonce = $dpop_private_key !== null ? DpopProof::nonceChallenge($response) : null;

        if ($dpop_nonce !== null) {
            AuditLog::create(
                event_type: 'okta.api.auth.dpop.nonce',
                level: 'debug',
                message: 'DPoP nonce challenge received, retrying token request with nonce',
                method: __METHOD__,
                transaction: false
            );

            $response = $send($dpop_nonce);
        }

        $body = $response->object();

        if (! $response->successful() || ! is_object($body) || ! property_exists($body, 'access_token')) {
            $error = $body;
            $reason = 'Unknown response in the sendTokenRequest method.';

            if (is_object($error) && property_exists($error, 'error_description')) {
                $reason = $error->error_description;
            } elseif (is_object($error) && property_exists($error, 'errorSummary')) {
                $reason = $error->errorSummary;
            }

            AuditLog::create(
                errors: [$reason],
                event_type: 'okta.api.auth.error',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                metadata: [
                    'dpop' => $dpop_private_key !== null,
                    'status_code' => $response->status(),
                    'url' => $token_url,
                ],
                transaction: true
            );

            $dpop_required = is_object($error)
                && ($error->error ?? null) === 'invalid_dpop_proof'
                && $dpop_private_key === null;

            throw new UnauthorizedException(implode(' ', [
                'Okta API OAuth token request error.',
                '(Reason) ' . $reason,
                $dpop_required
                    ? '(Solution) The Okta service app requires DPoP. Set `OKTA_API_DPOP=true` in your .env file, or'
                        . ' `dpop` to `true` in the connection array.'
                    : '(Solution) Verify the client_id, registered public key, and that the requested scopes are'
                        . ' granted to the service app.',
            ]));
        }

        AuditLog::create(
            event_type: 'okta.api.auth.success',
            level: 'debug',
            message: 'Success',
            metadata: [
                'token_type' => $body->token_type ?? null,
            ],
            method: __METHOD__,
            transaction: false
        );

        return [
            'access_token' => $body->access_token,
            'token_type' => $body->token_type ?? 'Bearer',
        ];
    }
}
