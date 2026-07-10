<?php

return [
    /**
     * The URL of your Okta instance
     *
     * @example https://example.okta.com
     * @example https://trial12345678.okta.com
     */
    'url' => env('OKTA_API_URL'),

    /**
     * Whether PHP exceptions are thrown if the API experiences an error
     *
     * All requests including 4xx and 5xx errors are logged using the audit and
     * event log, including with ERROR and CRITICAL log levels. If you log level
     * in Laravel catches the problem with your bug report, then you may not
     * need these exceptions. If you want to handle problems behind the scenes
     * without users seeing an error message, then you can disable this.
     *
     * @see vendor/boldlygrow/okta-api-client/src/Exceptions
     */
    'exceptions' => env('OKTA_API_EXCEPTIONS', true),

    /**
     * Legacy Authentication with SSWS API Token
     *
     * An SSWS (Server-Side Web Services) token is a static, proprietary API key
     * used by Okta for administrative and management API authentication. It
     * acts on behalf of the administrator who generated it and inherits their
     * exact access permissions.
     *
     * Prior to the popularity of OAuth, this served as a Personal Access Token
     * approach and you could create a user account with a bot/service name with
     * desired admin-level permissions. This is still supported and as simpler,
     * but is not considered secure or best practice anymore. It is recommended
     * to use OAuth 2.0 instead.
     *
     * The `SSWS ` prefix will be trimmed automatically if it's included.
     */
    'token' => env('OKTA_API_TOKEN'),

    /**
     * Modern Authentication with OAuth2 and JWT
     *
     * When `client_id` is set, the API client authenticates with a short-lived
     * OAuth 2.0 Bearer token (minted and cached by ApiToken) instead of the
     * legacy SSWS API token. This is the only client authentication method Okta
     * supports for management API scopes (okta.users.read, okta.groups.read,
     * okta.apps.read, etc.). Client secrets are not supported for these scopes.
     *
     * The scope is supplied per request as the scope argument. We intentionally
     * do not define a default scope. The scope is ignored if using SSWS token.
     */
    'client_id' => env('OKTA_API_CLIENT_ID'),

    /**
     * The Key ID (kid) of the public key registered on the Okta service app.
     *
     * Included in the signed JWT assertion header so Okta can select the
     * correct verification key. Required once the app has more than one
     * registered public key, and harmless to always set.
     */
    'key_id' => env('OKTA_API_KEY_ID'),

    /**
     * JWT Signing Private Key
     *
     * The RSA private key used to sign the JWT client assertion, provided one
     * of two ways. Provide one or the other; `private_key` takes precedence.
     *
     * This package does not integrate with any secrets manager. Retrieving the
     * key from a vault or secrets manager is your application's responsibility,
     * which keeps this package independent of your storage choice and free of
     * cloud SDK dependencies.
     *
     *   1. `private_key` (OKTA_API_PRIVATE_KEY): an inline PEM string. This is
     *      the primary mechanism. Resolve the key from your secrets manager in
     *      your own code and pass it in the connection array, or set this value
     *      after publishing the config. See the README for both patterns.
     *   2. `private_key_path` (OKTA_API_PRIVATE_KEY_PATH): a filesystem path to
     *      a PEM file, for local development or a secret mounted as a file. A
     *      leading `~` is expanded to the home directory.
     */
    'private_key' => env('OKTA_API_PRIVATE_KEY'),
    'private_key_path' => env('OKTA_API_PRIVATE_KEY_PATH'),
];
