# Changelog

All notable changes to `boldlygrow/okta-api-client` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.1.0] - 2026-10-31

### Added

- **PHPStan types for API responses**
    - `ApiClient` defines the `ApiResponse`, `ApiPaginatedResponse`, `ApiErrorResponse`, `ApiResponseStatus`, and `ApiResponseHeaders` types. Import them in your application with `@phpstan-import-type ApiResponse from ApiClient`.
    - `get()`, `post()`, `patch()`, `put()`, and `delete()` are documented as returning `ApiResponse` instead of `object`, and every `$connection` parameter is documented as `array<string, mixed>`.
    - **Why:** With a return type of `object`, PHPStan and Larastan couldn't check the `data`, `headers`, and `status` properties that you read from a response.

### Changed

- **DPoP is enabled by default**
    - `OKTA_API_DPOP` (the `dpop` config key) and the connection array `dpop` key now default to `true`. Both defaulted to `false` in 5.0.0.
    - **Why:** A DPoP-bound access token cannot be replayed if it leaks, and we recommend leaving **Require Demonstrating Proof of Possession (DPoP) header in token requests** enabled on the Okta service app. The default now matches that recommendation.
    - **How:** If your service app does not have the DPoP setting enabled, set `OKTA_API_DPOP=false`, or `'dpop' => false` in each connection array, before you upgrade. SSWS connections are unaffected.
    - When the service app requires DPoP and the client has it disabled, the `UnauthorizedException` now says to remove `OKTA_API_DPOP=false` or the connection array `dpop` key, instead of setting it to `true`.
    - This is a minor release instead of a patch because OAuth connections that did not set `dpop` change behavior.
- **The README recommends loading secrets into environment variables**
    - Most applications run in a container or on a VM, where the platform can load secrets manager values into `OKTA_API_CLIENT_ID` and `OKTA_API_PRIVATE_KEY` at runtime. Added examples for Amazon ECS with Parameter Store and Cloud Run with Secret Manager.
    - If your platform can't set environment variables from a secrets manager, fetch the secrets for the whole application in the published `config/okta-api-client.php`. Added examples for AWS Systems Manager Parameter Store and Google Secret Manager, with notes on config caching and secret rotation. This package still doesn't depend on either SDK.
    - Connection arrays are recommended when the credentials vary per tenant or change at runtime.
    - **Why:** The README recommended connection arrays for any secrets manager, but they are only needed when the credentials change at runtime. The credentials for a single service app rarely change, and most platforms can load them into environment variables without any code.
    - Replaced the `app(OktaKeyResolver::class)` config file example, which could not use the `Cache` facade that it recommended, since config files load before service providers.
    - `php artisan okta-api-client:jwk --generate` now points to the README's **Private Key Storage** section instead of recommending Google Secret Manager and a `~/.config/provisionr/dev` directory.
- **The README separates the OAuth 2.0 and SSWS setup**
    - **Environment Variables** is split into **OAuth 2.0 Variables** and **SSWS API Token Variables (Legacy)**. Each variable is marked as required, as one of the two private key options, or as optional.
    - The OAuth 2.0 and SSWS connection array examples have their own headings.
    - The OAuth 2.0 quick start says to choose the classic experience when you create the service app, and to save the public key in the **Public Keys** section before you edit **Client Credentials**.
    - The SSWS quick start warns against creating the token with a Super Admin account.
    - **Why:** The single variables table mixed OAuth 2.0 and SSWS variables, so it wasn't clear which ones each setup needed.

### Fixed

- **Every 4xx and 5xx response throws the exception for its status code**
    - In 5.0.0, a status code without its own log event type, such as `206`, `409`, `410`, `502`, or `504`, failed with an `ErrorException` (`Undefined array key`) while the response was being logged, even with `OKTA_API_EXCEPTIONS=false`. A `304` failed with a `TypeError`.
    - A `409` now throws `ConflictException`, which 5.0.0 never reached. Any other 4xx or 5xx code without its own exception throws `BadRequestException` or `ServerErrorException`, as the README's exceptions table describes.
    - A `501` returned the response without an exception in 5.0.0, and now throws `ServerErrorException` when `OKTA_API_EXCEPTIONS=true`.
    - A `409` is logged as `okta.api.{method}.error.conflict`. Other status codes without their own event type are logged as `okta.api.{method}.success` (2xx), `okta.api.{method}.error.client-error` (4xx), `okta.api.{method}.critical.server-error` (5xx), or `okta.api.{method}.warning.unexpected-status` (anything else). The README's **Event Types** table lists them.
    - **Why:** An `ErrorException` doesn't include the status code or the reason that Okta returned, and it was thrown before the request was logged, so these failures couldn't be handled or searched for.
    - **How:** If you catch `ErrorException` around API calls, catch the exceptions in `BoldlyGrow\Okta\Exceptions` instead. If you handle a `501` response object with exceptions enabled, catch `ServerErrorException` instead.
- **Connections that can't be JSON encoded no longer share a cached access token**
    - The token cache key is an MD5 hash of the JSON-encoded `url`, `client_id`, `key_id`, `private_key_path`, scope string, and DPoP setting. In 5.0.0, if one of these values wasn't valid UTF-8, encoding failed and the key was the hash of an empty string.
    - Every connection that failed to encode shared that one cache key, so a connection could be given an access token that was minted for a different service app or scope.
    - `ApiToken::create()`, and any API request that needs a token, now throws a `JsonException` instead.
    - **Why:** An access token carries the scopes and admin role of the service app that minted it, so it must never be reused for another connection.
    - **How:** If you see a `JsonException` from `ApiToken`, check the connection values and the `scope` argument for invalid UTF-8 bytes.
- The README's **Event Types** table lists `412` and `422` responses at the `ERROR` level that they're logged at, instead of `DEBUG`.
- `ApiClient::handleException()` returns `data` (`null`) and `headers` (an empty array) with the `error` and `status` properties, so code that reads `$response->data` from an error response doesn't fail with an undefined property.
- `php artisan okta-api-client:jwk --generate` prints an error and exits with a failure code if OpenSSL can't export the generated key pair. In 5.0.0, it could write an empty private key file and print the public JWK as if it had succeeded.

## [5.0.0] - 2026-10-31

### Overview

This release adds OAuth 2.0 authentication, moves the package to the `BoldlyGrow` namespace, and updates the supported Laravel versions.

- **OAuth 2.0 with optional DPoP** is available as an alternative to the SSWS API token.
- **The package is renamed** from `provisionesta/okta-api-client` to `boldlygrow/okta-api-client`.
- **Laravel v11, v12, and v13 are supported.** Support for Laravel v8, v9, and v10 is dropped.
- **SSWS API tokens still work.** Existing single-token deployments need no changes beyond the package and namespace rename.

### Added

#### Framework Support

- **Laravel v13 support**
    - The `illuminate/*` constraints are `^11.0 || ^12.0 || ^13.0`. Laravel v8, v9, and v10 are no longer supported (see **Removed**).

#### OAuth 2.0 Authentication

- **Authenticate with OAuth 2.0 as an alternative to the SSWS API token**
    - Uses the Client Credentials grant with a `private_key_jwt` client assertion.
    - When `client_id` is set, the client sends an OAuth access token. Otherwise it falls back to `SSWS`.
    - **Why:** SSWS tokens are long-lived, org-wide credentials that are tied to an individual and expire after inactivity. An OAuth service app is scoped to exactly what it needs (for example read-only users, groups, and apps), rotates without human involvement, and uses a private key instead of a shared secret.
    - **How:** Create an Okta API Services app with `Public key/Private key` client authentication, register the public JWK, and grant the Okta API scopes it needs. Then set `OKTA_API_CLIENT_ID`, `OKTA_API_KEY_ID`, and a private key variable.
    - **Note:** Client secrets are intentionally not supported. Okta only accepts `private_key_jwt` for tokens with management API scopes, and rejects a client secret with `invalid_client: Client Credentials requests to the Org Authorization Server must use the private_key_jwt token_endpoint_auth_method.` A client secret only works with a custom authorization server, whose custom scopes cannot read Okta resources.
- **Access tokens are minted and cached by the new `ApiToken` class**
    - Returns a short-lived, scoped Bearer or DPoP-bound access token.
    - One token is requested per unique connection, scope set, and DPoP setting. It is encrypted with the application's `APP_KEY` and cached for 59 minutes by default, since Okta fixes the token lifetime at one hour.
    - Set the cache lifetime with the `token_cache_minutes` config key or `OKTA_API_TOKEN_CACHE_MINUTES`, as a whole number from 1 to 59. Any other value throws a `ConfigurationException`.
    - A token that Okta rejects with a `401` is removed from the cache with `ApiToken::forget()`, so the next request mints a new one. A `use_dpop_nonce` challenge does not remove it.
        - **Why:** A revoked token, or one minted before the service app's granted scopes changed, would otherwise keep failing for the rest of the cache lifetime.
    - A successful token endpoint response that is not JSON (ex. an HTML page from a proxy) throws an `UnauthorizedException` with the reason.
- **`key_id` is required for OAuth connections**
    - It is always sent as the `kid` header of the client assertion. A missing `key_id` (`OKTA_API_KEY_ID`) throws a `ConfigurationException` before a token is requested.
    - **Why:** Okta rejects a client assertion without a `kid`, and the token endpoint's error for it is less specific.
- **Create a JWK with the `okta-api-client:jwk` Artisan command**
    - Converts an RSA public key to a JWK for the Okta service app, or generates a new key pair.
    - The `PublicKeyJwk` action does the same conversion in code.

#### Demonstrating Proof of Possession (DPoP)

- **Bind access tokens to the client with DPoP ([RFC 9449](https://datatracker.ietf.org/doc/html/rfc9449))**
    - Enable it with `OKTA_API_DPOP=true` or the `dpop` connection array key. It is disabled by default and implemented by the new `DpopProof` class.
    - **Why:** When the Okta service app has **Require Demonstrating Proof of Possession (DPoP) header in token requests** enabled, a token request without a DPoP proof is rejected with `invalid_dpop_proof`. A DPoP-bound token cannot be replayed if it leaks, because every request must also carry a single-use proof signed by a key that only the client holds.
    - **How:**
        - Each time an access token is minted, a new ephemeral RSA key pair is generated, separate from the `private_key_jwt` signing key, and cached (encrypted) with the token.
        - The token request carries a DPoP proof. After Okta's `use_dpop_nonce` challenge, it is retried with the server-provided nonce and a new client assertion.
        - Each API request uses the `DPoP` authorization scheme and a new proof bound to the HTTP method, URL, and access token. The URL excludes the query string and is percent-decoded, since Okta compares it to the decoded request URL.
        - A `use_dpop_nonce` challenge from the API is retried once with the nonce.
    - If the service app requires DPoP but it is not enabled in the client, the `UnauthorizedException` message explains how to enable it.
    - The authorization scheme follows the `token_type` that Okta returns, so a `Bearer` token is still sent as `Bearer` if Okta does not bind the token.

#### Scopes

- **Pass a `scope` on each call for least-privilege access**
    - Added to `get`, `post`, `patch`, `put`, `delete`, and `testConnection()`, mirroring the Google API client. It is an optional trailing parameter, so existing SSWS calls are unaffected.
    - Required for OAuth connections. There is no config or environment default, and omitting it throws a `ScopeException`.
    - **Why:** The scopes granted to the Okta service app are the hard least-privilege ceiling. Requiring the scope at the call site keeps the requested access next to the operation that needs it, and avoids a broad default that silently widens every token.
    - SSWS connections ignore the `scope` argument.
    - All granted scopes can be requested in a single token, so one token is reused across users, groups, and apps requests.
    - Each unique scope set is cached separately for the full token lifetime, so per-call scoping mints a few more tokens per hour without shortening caching.

#### Private Key Resolution

- **Provide the signing key as an inline PEM or a PEM file path**
    - Use `private_key` for an inline PEM string or `private_key_path` for a file path (a leading `~` is expanded). `private_key` takes precedence.
    - **Why:** Two simple key sources avoid confusing a logical secret name with a file path, and keep the package free of any secrets-manager or cloud SDK coupling.
    - This package intentionally does not integrate with any secrets manager. Your application fetches the key and passes the resolved PEM in the connection array or the published config. See [Private Key Storage](README.md#private-key-storage) for both patterns.
    - The key is only resolved on a token cache miss. The token cache key includes the `kid` and either the file path or a fingerprint of the inline key, so a rotated key takes effect without stale caching.

#### Connections and Configuration

- **New configuration keys for OAuth**
    - `client_id`, `key_id`, `private_key`, `private_key_path`, `dpop`, `token_cache_minutes`, and `test_uri`. Scope is passed per request, not configured.
- **Choose the endpoint that `testConnection()` probes**
    - Still probes `org` by default, but now honors `OKTA_API_TEST_URI`.
    - Accepts a `scope` argument for OAuth connections (ex. `ApiClient::testConnection(scope: 'okta.orgs.read')`).
    - **Why:** The `org` endpoint requires the `okta.orgs.read` scope. A service app granted only users, groups, and apps read access would otherwise fail the connection test despite valid credentials.

#### Tooling

- **Pest test suite**
    - Lives in `tests/Feature`, uses Orchestra Testbench, and runs with `composer test`, using the same layout as `boldlygrow/audit-log`.
    - Every Okta endpoint is faked with `Http::fake()` and stray requests are prevented, so the suite never reaches the network.
    - Adds `pestphp/pest` and `pestphp/pest-plugin-laravel` as development dependencies. Running the suite requires PHP v8.3 or newer, but the package itself still supports PHP v8.2.

### Changed

#### Package Architecture

- **Renamed to `boldlygrow/okta-api-client` with the `BoldlyGrow\Okta` namespace**
    - The Composer package changed from `provisionesta/okta-api-client` to `boldlygrow/okta-api-client`.
    - The PHP namespace changed from `Provisionesta\Okta` to `BoldlyGrow\Okta`.
    - **Why:** The project is consolidating under the maintainer's `BoldlyGrow` organization alongside the related audit and actions packages.
    - The `provisionesta/okta-api-client`, `gitlab-it/okta-sdk`, and `glamstack/okta-sdk` package names are kept as `replace` aliases for a smoother upgrade.

#### Dependencies

- **Audit logging uses `boldlygrow/audit-log` instead of `provisionesta/audit`**
    - The imported class changed from `Provisionesta\Audit\Log` to `BoldlyGrow\AuditLog\AuditLog`.
- **Added `illuminate/console` and `ext-openssl` requirements**
    - Needed for the new Artisan command and for JWT signing.

#### Error Handling

- **Exception messages include the reason that Okta returned**
    - The format is `METHOD CODE URL (Reason) <errorCode> <errorSummary> (Causes) <errorCauses>`.
    - **Why:** Only `errorCode` and `errorSummary` were read before. For a validation failure, `errorSummary` only names the object that failed (ex. `Api validation failed: profile`). The actionable per-attribute reason is in `errorCauses`, which was silently discarded.
    - `errorCauses` entries are flattened onto the message (ex. `(Causes) firstName: The field cannot be left blank, email: Does not match required pattern`).
    - The audit log `errors.error_message` value contains the same parsed reason. `errors.error_code` is unchanged.
    - **No action is required** unless you parse exception message strings. The existing exception classes, status code mapping, and `OKTA_API_EXCEPTIONS` behavior are unchanged.
- **The reason is also read from OAuth errors and the `WWW-Authenticate` header**
    - The `error` and `error_description` keys returned by the OAuth 2.0 token endpoints are read.
        - **Why:** These responses contain neither `errorCode` nor `errorSummary`, so an OAuth failure would otherwise show only the method, status code, and URL followed by two trailing spaces.
    - If the response body is empty, the `error` and `error_description` from the `WWW-Authenticate` response header are used (ex. `(Reason) invalid_dpop_proof 'htu' claim in the DPoP proof JWT is invalid.`).
        - **Why:** Okta returns OAuth 2.0 resource server failures, such as a rejected DPoP proof, as a `400` or `401` with an empty body and the reason only in this header.
    - If the body uses none of these keys, it is appended (truncated to 1000 characters) instead of being discarded.
- **`ServerErrorException` uses the same message format**
    - It was previously thrown with a `json_encode()` of the response body, which dropped the method and URL and produced the string `null` for a non-JSON body.
- **A decoded copy of the URL is added to the message**
    - It is appended when it differs from the requested URL (ex. a `filter` query string). The encoded URL is still shown first, because it is the one that reproduces the request.
- **`401` messages match the authentication mode**
    - Under OAuth, the message points to token expiry, scope grants, and JWK registration. Under SSWS, it points to token rotation in `OKTA_API_TOKEN` or the connection array `token`.
    - **Why:** The mode is taken from the connection used for the request, not the config. A request with an SSWS connection array is not reported as an OAuth failure when the config has a `client_id`, and vice versa.

#### Connections and Configuration

- **`validateConnection()` accepts either a `token` or a `client_id`**
    - `token` is for SSWS and `client_id` is for OAuth. Validated keys are merged over the source connection array, so OAuth keys provided at runtime are preserved through to `ApiToken`.
    - Both keys are nullable, so the unused credential from the published config (an unset `OKTA_API_TOKEN` or `OKTA_API_CLIENT_ID` resolves to `null`) does not fail the format rules of the other authentication mode.

#### Documentation

- **Release notes are consolidated in this file**
    - Adopted the Keep a Changelog format and replaced the per-version `changelog/*` files with this top-level `CHANGELOG.md`.

### Removed

- **Laravel v8, v9, and v10 and PHP v8.0 and v8.1 are no longer supported**
    - The package requires PHP `^8.2` and Laravel `^11.0`, `^12.0`, or `^13.0`.
    - **Why:** These versions are past their maintenance windows. The `boldlygrow/audit-log` dependency already requires Laravel v11+ and PHP v8.2+, so the legacy ranges could not resolve in practice.
    - **How:** Upgrade your application to Laravel v11+ and PHP v8.2+ before moving to `boldlygrow/okta-api-client:^5.0`. Applications on Laravel v8, v9, or v10 should stay on `provisionesta/okta-api-client:^4.0`.

### Fixed

- **A `503` response throws the new `ServiceUnavailableException`**
    - **Why:** `503` was mapped in the log types as `critical.server-unavailable`, but the exception switch had no case for it. No exception was thrown, and the caller silently received a failed response object.

### Upgrade Steps

1. **Upgrade Laravel and PHP first.** Your application must run Laravel v11, v12, or v13 on PHP v8.2 or newer. Applications on Laravel v8, v9, or v10 must upgrade the framework first, or stay on `provisionesta/okta-api-client:^4.0`.
2. **Update the package.** Require `boldlygrow/okta-api-client:^5.0` in `composer.json` and run `composer update`.
3. **Update the namespace.** Find and replace `Provisionesta\Okta` with `BoldlyGrow\Okta` across your code base.
4. **Update audit logger references.** If you reference the audit logger directly, replace `Provisionesta\Audit` with `BoldlyGrow\AuditLog`.
5. **Re-publish the config file** so the new OAuth keys are present: `php artisan vendor:publish --tag=okta-api-client`.
6. **SSWS deployments are done.** Keep `OKTA_API_URL` and `OKTA_API_TOKEN` as they are.
7. **(Optional) Move a connection to OAuth.**
    - Create an Okta API Services app with `Public key/Private key` client authentication and register your public JWK.
    - Grant the app the Okta API scopes it needs (for example `okta.users.read`, `okta.groups.read`, `okta.apps.read`) and assign a least-privilege admin role.
    - Set `OKTA_API_CLIENT_ID` and `OKTA_API_KEY_ID` (required). Scopes are passed per call with the `scope` argument, not an environment variable.
    - Provide the private key with `OKTA_API_PRIVATE_KEY_PATH` (a PEM file) or `OKTA_API_PRIVATE_KEY` (an inline PEM), or pass `private_key` in the connection array. For production, resolve the key from your secrets manager in your own code and pass it in the connection array.
    - If the service app is not granted `okta.orgs.read`, set `OKTA_API_TEST_URI` to an endpoint it can reach.
    - If the service app has **Require Demonstrating Proof of Possession (DPoP) header in token requests** enabled, set `OKTA_API_DPOP=true` (or `'dpop' => true` in the connection array). No additional keys are needed.
8. This is not an exhaustive list. Review the `README.md` for the latest syntax and usage guidelines.

*Releases prior to 5.0 were developed on GitLab as `provisionesta/okta-api-client` (4.x), `gitlab-it/okta-sdk` (3.x), and `glamstack/okta-sdk` (2.x). The `!N` tokens in the entries below are GitLab merge request references, retained for historical accuracy.*

## [4.2.0] - 2025-04-27

### Added

- Add Laravel v12.x support

## [4.1.0] - 2024-03-18

### Changed

- **`testConnection()` relies on `get()` for error handling**
    - It no longer performs its own response check. A failed connection test is handled the same as any other request.
    - **Why:** The check duplicated the error handling and logging that `get()` already performs.
- **Failed paginated requests are labeled `GET|PAGINATED`**
    - Exception messages previously started with `GET`.
    - **Why:** A failure on a later page of results can be told apart from a single `GET` request in the logs.
- **The `User-Agent` header includes `provisionesta/okta-api-client`**
    - It appears between the application's Composer package name and the Laravel version.
    - **Why:** Requests sent by this package can be identified in the Okta System Log.

### Fixed

- **`ServerErrorException` for a `500` response contains the response data**
    - The JSON encoded response data is used. It previously read the `json` response property, which was removed in 4.0.

## [4.0.0] - 2023-12-29

### Overview

This release focuses on developer experience optimization and simplifying or removing features that are no longer relevant based on lessons learned, and cosmetics when using named arguments/parameters.

### Added

- Add Laravel v11.x support
- Add PHP v8.3 support
- **Exceptions are thrown for all 4xx and 5xx errors**
    - **Why:** Applications needed a lot of code to check status codes after each request, which was difficult to maintain in multiple places. Throwing exceptions centrally removes that code, and you can catch the exception where it matters in your application and handle it gracefully.
- **Rate limit backoff for large data sets**
    - When less than 20% of the rate limit remains, each paginated request waits 10 seconds so the limit can reset before the next page is fetched.
    - **Why:** There was no graceful way to handle the rate limit yourself with large data sets, which resulted in failed requests.
- **`patch()` method for partial updates to an existing record**
    - Sends a `POST` request behind the scenes, because the Okta API uses non-standard `POST` requests for partial updates instead of `PATCH`.
    - **Why:** Update calls can follow the industry convention for `PATCH`. The `post()` method can still be used for updates.
- Add `provisionesta/audit` package for standardized logging
- Add documentation for named arguments/parameter usage
- **Laravel Collection example usage documentation**
    - **Why:** Most API responses need to be parsed into usable data sets. These examples show best practices for last mile coding.

### Changed

#### Package Architecture

- **Renamed from `gitlab-it/okta-sdk` to `provisionesta/okta-api-client`**
    - The PHP namespace changed from `GitlabIt\Okta` to `Provisionesta\Okta`.
    - **Why:** The maintainers are no longer in GitLab IT, so this project moved to the maintainer's personal open source project namespace (codename Provisionesta) for ongoing contributions.
- License copyright owner changed from `GitLab B.V.` to `Jefferson Martin`

#### Connections and Configuration

- **Environment variables renamed to `OKTA_API_URL` and `OKTA_API_TOKEN`**
    - `OKTA_{CONNECTION}_BASE_URL` is now `OKTA_API_URL`.
    - `OKTA_{CONNECTION}_API_TOKEN` is now `OKTA_API_TOKEN`.
    - **Why:** The variables now match the package name, after the rename from `okta-sdk` to `okta-api-client` and the addition of the related `okta-laravel-actions` package in the same `Provisionesta\Okta` namespace. No other URLs are configured, so `BASE_` was cosmetic and was dropped for simplicity.

#### API Requests

- **`request_data` argument renamed to `data`**
    - **Why:** Named arguments are now common, and typing out `request_data` is annoying. It also avoids naming variations like `query_string` or `form_data`.
- **`connection_config` argument renamed to `connection` and moved to the last position**
    - **Why:** Without connection keys, the argument is optional, so it belongs after the endpoint URI and request data. The shorter name is easier to type with named arguments, which can still be passed in any position (ex. first, alphabetical, or last).
- A `429` rate limit error throws `\Provisionesta\Okta\Exceptions\RateLimitException` instead of `\Exception`.

#### API Responses

- **`object` response property renamed to `data`**
    - **Why:** Without the `json` property, the name `object` no longer made sense. `data` follows other API schema standards, and it is cast as an object.
- **Response keys are sorted alphabetically: `data`, `headers`, `status`**
    - **Why:** `data` is what most responses are used for, so `headers` no longer needs to appear at the top.

#### Logging

- **New log format using `provisionesta/audit`**
    - The event type syntax changed to dot notation.
    - See [provisionesta/audit](https://gitlab.com/provisionesta/audit) for details on the keys that can be used.
    - See [Log Examples](https://gitlab.com/provisionesta/okta-api-client/-/blob/main/README.md#log-examples) in the README for example logs and all event types.

### Removed

- **Connection keys are no longer supported**
    - **Why:** Advanced use cases with multiple connection keys usually used dynamic database variables or secrets manager values. Developers often commented out and uncommented blocks of variables instead of switching the default connection. A single set of `OKTA_API_URL` and `OKTA_API_TOKEN` variables aligns with common configuration across packages in the Laravel ecosystem.
    - **Workaround:** Define your own connection arrays and pass them at runtime.
- **Custom log channels are no longer supported**
    - **Why:** Production applications usually export logs to a centralized service, often alongside STDOUT and STDERR logs from container or serverless environments. Falling back to the application's default log channel or stack removes the separation complexity.
- **`config/okta-sdk.php` is no longer needed**
    - **Why:** With only the `OKTA_API_URL` and `OKTA_API_TOKEN` environment variables, and all other connections provided at runtime, a customized configuration file is not needed. The package's default config file provides a flat array that reads the `.env` values.
- **The `json` response property was removed**
    - **Why:** It doubled the payload size for large responses, which increased the memory footprint, and it was rarely used.
    - **Workaround:** Use `json_encode($response->data)`.

### Upgrade Steps

1. **Re-read the `README.md`** to see the latest syntax and usage guidelines.
2. **Update your `.env` file.**
    - Choose your primary connection.
    - Rename `OKTA_{PRIMARY_CONNECTION}_BASE_URL` to `OKTA_API_URL`.
    - Rename `OKTA_{PRIMARY_CONNECTION}_API_TOKEN` to `OKTA_API_TOKEN`.
    - If you have additional connections, comment out those variables and rename the keys as well.
    - Exceptions are thrown for 4xx and 5xx errors. If you do not want to catch exceptions, disable them by adding `OKTA_API_EXCEPTIONS=false` to your `.env` file.
3. **Delete the `config/okta-sdk.php` file.**
4. **Update the package.** Remove `gitlab-it/okta-sdk` from `composer.json`, add `"provisionesta/okta-api-client": "^4.0"`, and run `composer update`.
5. **Update the namespace.** Find and replace `GitlabIt\Okta` with `Provisionesta\Okta` across your code base.
6. **Search for `config('okta-sdk.`** and evaluate what changes are needed, since the file no longer exists.
7. **Update code that uses the API client.**
    - `->json`: Refactor your code to use `json_encode($response->data)`.
    - `->object`: Refactor your code to use `->data`.
    - `new ApiClient`: Remove all instances, since the methods are static now. Refactor your code from `$okta_api->get('connection_key', 'endpoint')` to `ApiClient::method('endpoint')`.
    - `->status`: Refactor your code to catch the exceptions instead.
8. **Update your centralized log platform** to use the new `event_type` keys.
9. This is not an exhaustive list. Evaluate all of your code that uses the API after reading the latest version of the README.

## [3.0.1] - 2023-02-27

### Fixed

- !36 Fix documentation typos in `ApiClient` comments and `README.md`, including the groups `POST` and `PUT` examples that were missing the nested `profile` array

## [3.0.0] - 2023-02-26

### Added

- !30 Add Laravel v10.x support
- !30 Add PHP v8.2 support
- !34 Add throwable `429` exception when Okta rate limit exceeded
- !18 **GitLab CI pipeline jobs in `.gitlab-ci.yml`**
    - Add `gitlab-it/laravel-docker` Docker image as default image
    - Add Code Quality CI template job
    - Add Dependency Scanning template job
    - Add Security/SAST template job
    - Add Secret Detection template job
    - Add PHPCS PSR-12 code style job

### Changed

- !17 The `glamstack/okta-sdk` Composer package and namespace has been abandoned and has been renamed to `gitlab-it/okta-sdk`. Update your version constraint to `composer require gitlab-it/okta-sdk:^3.0`.
- The PHP namespace changed from `Glamstack\Okta` to `GitlabIt\Okta`.
- The `config/glamstack-okta.php` file has been renamed to `config/okta-sdk.php`. No array changes were made.
- The config publish tag changed from `glamstack-okta` to `okta-sdk` (`php artisan vendor:publish --tag=okta-sdk`).
- Changed from a modified version of [Calendar Versioning (CalVer)](https://calver.org/) to using [Semantic Versioning (SemVer)](https://semver.org/).
- License changed from `Apache 2.0` to `MIT`.
- !26 An error that previously was handled with `abort()` will now throw a new `\Exception`.
- **`ApiClient::handleException` returns an `object` instead of a `string`**
    - This normalizes the exception response format with the normal response's `error` and `status` arrays.
    - **Impact:** An exception no longer returns a string error message. It returns an object with `error.code`, `error.message`, `error.reference`, and the standard `status.*` properties. `status.serverError` is always `true` for an exception. The `object.*`, `json.*`, and `headers.*` properties are not returned in an exception response.
- Update the `ApiClient::delete`, `get`, `post`, and `put` return types from `object|string` to `object`.
- !30 Changed Composer package dependency from `laravel/framework` to use individual `illuminate/*` packages.
- Update `ApiClient::parseApiResponse` to refactor check for `paginated_results` property in response
- Fix PSR-12 formatting in affected files
- !32 Update `CONTRIBUTING.md` and `README.md` with `okta-sdk` and related v3 content updates

### Removed

- Remove `$paginated` optional argument from `ApiClient::parseApiResponse` method

### Fixed

- !25 Add missing `$request_data` to `ApiClient::get` and `getPaginatedResults` method when parsing paginated results
- !33 Fix missing info logs for API responses that were successful (2xx) but not OK (200)
- !16 Fix Okta pagination results to include first 200 results

### Upgrade Steps

1. **Update the package.** Remove `glamstack/okta-sdk` from `composer.json`, add `"gitlab-it/okta-sdk": "^3.0"`, and run `composer update`.
2. **Rename the config file.** In your `config` directory, rename `glamstack-okta.php` to `okta-sdk.php`.
3. **Update the namespace.** Find and replace `Glamstack\Okta` with `GitlabIt\Okta` across your code base.
4. **Update config references.** Find and replace `config('glamstack-okta.` with `config('okta-sdk.`.

## [2.2.24] - 2022-02-24

### Added

- !12 Add support for Laravel v9.x
- !13 **`connection_config` construct parameter**
    - Array parameter to override the configuration file
    - Array keys are validated

### Removed

- !13 **The `api_token` construct parameter was removed from `ApiClient`**
    - This parameter has been replaced with the `connection_config` parameter.
    - **Affected Users:** This breaking change is limited to developers that overrode the `api_token` parameter during the initialization of the SDK.
    - **Symptoms:** You will see the following Type Error when utilizing the SDK.

        ```bash
        TypeError: Glamstack\Okta\ApiClient::__construct(): Argument #2 ($connection_config) must be of type array, string given on line 1
        ```

    - **Resolution:** Utilize the new `connection_config` construct parameter. This parameter is an array that requires the `base_url`, `api_token`, and `log_channels` to be set.

## [2.2.1] - 2022-02-01

### Overview

This is the first release of the Okta SDK that is maintained by GitLab IT Engineering for use in our internal Laravel applications for connecting to multiple Okta instances and performing API calls.

### Added

- !2 Add `ApiClient` class that can be used in any Laravel class (Controllers, Models, Services, etc.)
- Add `get()` method that supports list and single record API requests. This includes automatic recursive pagination handling using the `after` key in Okta API header `link` responses.
- Add `post()` method
- Add `put()` method
- Add `delete()` method
- Add support for multiple Okta instances with different Access Tokens
- **Comprehensive logging**
    - Add support for multiple Laravel logging channels for each connection
    - Add info and error logging for all API requests
    - Add Okta request ID and rate limit values to all logs
    - Add `User-Agent` request headers that include Composer package version, Laravel package version, and PHP package version that appear in audit logs on the Okta instance (Ex. `Glamstack-Okta-Sdk/2.2.1 Laravel/8.81.0 PHP/8.1.2`).

### Changed

- !2 **The `log_channels` config key moved under `auth` and each connection**
    - `config('glamstack-okta.log_channels')` is now `config('glamstack-okta.auth.log_channels')` and `config('glamstack-okta.{connection_key}.log_channels')`.
    - **Affected Users:** This breaking change is limited to developers that were testing a pre-release using a branch (ex. `dev-main`) and published the configuration file to their application.
    - **Symptoms:** You will see errors that the `config('glamstack-okta.log_channels')` array does not exist.
    - **Resolution:** Follow the README steps for backing up and publishing the configuration file again. You will need to copy any customized configuration keys and values to the updated array schema. No updates to the `.env` variables are needed unless you customized the variable names in your original configuration.

[5.1.0]: https://github.com/boldlygrow/okta-api-client/compare/5.0.0...5.1.0
[5.0.0]: https://github.com/boldlygrow/okta-api-client/compare/4.2...5.0.0
[4.2.0]: https://github.com/boldlygrow/okta-api-client/compare/4.1...4.2
[4.1.0]: https://github.com/boldlygrow/okta-api-client/compare/4.0...4.1
[4.0.0]: https://github.com/boldlygrow/okta-api-client/compare/3.0.1...4.0
[3.0.1]: https://github.com/boldlygrow/okta-api-client/compare/3.0...3.0.1
[3.0.0]: https://github.com/boldlygrow/okta-api-client/compare/2.2.24...3.0
[2.2.24]: https://github.com/boldlygrow/okta-api-client/compare/2.2.1...2.2.24
[2.2.1]: https://github.com/boldlygrow/okta-api-client/releases/tag/2.2.1
