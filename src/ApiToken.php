<?php

/**
 * @copyright Jefferson Martin
 * @license MIT <https://spdx.org/licenses/MIT.html>
 * @link https://github.com/boldlygrow/okta-api-client
 */

namespace BoldlyGrow\Okta;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use BoldlyGrow\Audit\Log;
use BoldlyGrow\Okta\Exceptions\ConfigurationException;
use BoldlyGrow\Okta\Exceptions\ScopeException;
use BoldlyGrow\Okta\Exceptions\UnauthorizedException;

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
 * CACHING
 * The token is requested once per unique (url + client_id + scopes + key) combination, encrypted, and cached for 59
 * minutes. Okta fixes the access token lifetime at one hour. The cache key is built from stable identifiers only,
 * so the private key is resolved (and any file read happens) only on a cache miss.
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

    // Cache TTL in seconds. Okta access tokens live 3600s; 3540 leaves a 60s safety margin.
    public const CACHE_TTL = 3540;

    /**
     * Create (or return a cached) Okta API access token for the requested scopes.
     *
     * @param string|null $scope
     *      A required space-delimited OAuth scope string (ex. 'okta.users.read' or
     *      'okta.users.read okta.groups.read'). There is no config or environment default; a ScopeException
     *      is thrown if it is empty. Nullable only so the caller can forward a missing value and receive that
     *      exception rather than a type error.
     *
     * @param array $connection (optional)
     *      An array with `url`, `client_id`, either `private_key` or `private_key_path`, plus optional `key_id`.
     *      If not set, the `config('okta-api-client')` array is used.
     *
     * @throws ConfigurationException
     * @throws ScopeException
     * @throws UnauthorizedException
     */
    public static function create(?string $scope = null, array $connection = []): string
    {
        $validated_connection = self::validateConnectionArray($connection);

        $scopes = self::resolveScopes($scope);

        // Build the cache key from stable identifiers only, so the private key does not need to be read on a cache
        // hit. `key_ref` is the file path when using private_key_path, or a fingerprint of the inline key, so
        // rotating the key busts the cache.
        $cache_checksum_token = 'okta-api-token-' . md5(json_encode([
            'url' => $validated_connection['url'],
            'client_id' => $validated_connection['client_id'],
            'scopes' => $scopes,
            'key_id' => $validated_connection['key_id'] ?? null,
            'key_ref' => $validated_connection['private_key_path']
                ?? (isset($validated_connection['private_key'])
                    ? 'inline:' . md5((string) $validated_connection['private_key'])
                    : null),
        ]));

        $encrypted_token = Cache::remember(
            key: $cache_checksum_token,
            ttl: self::CACHE_TTL,
            callback: function () use ($validated_connection, $scopes) {
                // Resolve the private key only on a cache miss.
                $private_key = self::getPrivateKeyContents($validated_connection);

                $assertion = self::createClientAssertion(
                    url: $validated_connection['url'],
                    client_id: $validated_connection['client_id'],
                    key_id: $validated_connection['key_id'] ?? null,
                    private_key: $private_key
                );

                $api_token = self::sendTokenRequest(
                    url: $validated_connection['url'],
                    scopes: $scopes,
                    assertion: $assertion
                );

                return encrypt($api_token);
            }
        );

        return decrypt($encrypted_token);
    }

    /**
     * Validate that the required OAuth keys exist in the connection array.
     *
     * @param array $connection
     *
     * @throws ConfigurationException
     */
    private static function validateConnectionArray(array $connection): array
    {
        $connection_config = !empty($connection) ? $connection : config('okta-api-client');

        $validator = Validator::make(
            data: $connection_config,
            rules: [
                'url' => ['required', 'url:https'],
                'client_id' => ['required', 'string'],
                'key_id' => ['nullable', 'string'],
                'private_key' => ['nullable', 'string', 'required_without:private_key_path'],
                'private_key_path' => ['nullable', 'string', 'required_without:private_key'],
            ],
        );

        if ($validator->fails()) {
            Log::create(
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
                '(Solution) ' . $validator->messages()->first()
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
     * @param string|null $scope
     *
     * @throws ScopeException
     */
    private static function resolveScopes(?string $scope): string
    {
        $scopes = trim((string) $scope);

        if ($scopes === '') {
            $reason = 'An OAuth scope is required but was not provided.';

            Log::create(
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
                "ApiClient::get(uri: 'users', scope: 'okta.users.read')."
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
     * @param array $connection
     *
     * @throws ConfigurationException
     */
    private static function getPrivateKeyContents(array $connection): string
    {
        if (!empty($connection['private_key'])) {
            $private_key = (string) $connection['private_key'];
        } elseif (!empty($connection['private_key_path'])) {
            $path = self::expandHomeDirectory((string) $connection['private_key_path']);

            if (!is_readable($path)) {
                $reason = 'The private key file was not found or is not readable at (' . $path . ').';

                Log::create(
                    errors: [$reason],
                    event_type: 'okta.api.auth.validate.error.key',
                    level: 'critical',
                    message: 'Error',
                    method: __METHOD__,
                    transaction: true
                );

                throw new ConfigurationException(implode(' ', [
                    'Okta API OAuth configuration validation error.',
                    '(Reason) ' . $reason
                ]));
            }

            $private_key = (string) file_get_contents($path);
        } else {
            throw new ConfigurationException(implode(' ', [
                'Okta API OAuth configuration validation error.',
                '(Reason) No private key was provided.',
                '(Solution) Set `private_key` (an inline PEM string) or `private_key_path` (a PEM file path) in the',
                'connection array or config.'
            ]));
        }

        // Support keys stored on a single line with escaped newlines (ex. a local .env value).
        $private_key = str_replace('\n', "\n", $private_key);

        if (empty(trim($private_key)) || openssl_pkey_get_private($private_key) === false) {
            $reason = 'The resolved private key is empty or is not a valid PEM-formatted private key.';

            Log::create(
                errors: [$reason],
                event_type: 'okta.api.auth.validate.error.key',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: true
            );

            throw new ConfigurationException(implode(' ', [
                'Okta API OAuth configuration validation error.',
                '(Reason) ' . $reason
            ]));
        }

        return $private_key;
    }

    /**
     * Expand a leading ~ to the current user's home directory.
     *
     * @param string $path
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
     * @param string $url
     *      The Okta org base URL (ex. https://your-org.okta.com)
     *
     * @param string $client_id
     *      The service app client_id (used for both `iss` and `sub`)
     *
     * @param string|null $key_id
     *      The `kid` of the registered public key. Included in the JWT header when set so Okta can select the
     *      correct verification key from the app's JWKSet.
     *
     * @param string $private_key
     *      PEM-formatted RSA private key
     *
     * @throws ConfigurationException
     */
    private static function createClientAssertion(
        string $url,
        string $client_id,
        ?string $key_id,
        string $private_key
    ): string {
        $header = array_filter([
            'alg' => self::DEFAULT_ALGORITHM,
            'typ' => self::JWT_TYPE,
            'kid' => $key_id,
        ]);

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
     * @param string $signing_input
     * @param string $private_key
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

            Log::create(
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
     * @param string $algorithm
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
     *
     * @param string $input
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
     * @param string $url
     * @param string $scopes
     * @param string $assertion
     *
     * @throws UnauthorizedException
     */
    private static function sendTokenRequest(string $url, string $scopes, string $assertion): string
    {
        $response = Http::asForm()->post(
            url: rtrim($url, '/') . self::TOKEN_ENDPOINT_PATH,
            data: [
                'grant_type' => self::AUTH_GRANT_TYPE,
                'scope' => $scopes,
                'client_assertion_type' => self::CLIENT_ASSERTION_TYPE,
                'client_assertion' => $assertion,
            ]
        );

        if (!$response->successful() || !property_exists($response->object(), 'access_token')) {
            $error = $response->object();
            $reason = 'Unknown response in the sendTokenRequest method.';

            if (is_object($error) && property_exists($error, 'error_description')) {
                $reason = $error->error_description;
            } elseif (is_object($error) && property_exists($error, 'errorSummary')) {
                $reason = $error->errorSummary;
            }

            Log::create(
                errors: [$reason],
                event_type: 'okta.api.auth.error',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                metadata: [
                    'status_code' => $response->status(),
                    'url' => rtrim($url, '/') . self::TOKEN_ENDPOINT_PATH,
                ],
                transaction: true
            );

            throw new UnauthorizedException(implode(' ', [
                'Okta API OAuth token request error.',
                '(Reason) ' . $reason,
                '(Solution) Verify the client_id, registered public key, and that the requested scopes are granted',
                'to the service app.'
            ]));
        }

        Log::create(
            event_type: 'okta.api.auth.success',
            level: 'debug',
            message: 'Success',
            method: __METHOD__,
            transaction: false
        );

        return $response->object()->access_token;
    }
}
