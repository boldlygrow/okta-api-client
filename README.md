# Okta API Client

[[_TOC_]]

## Overview

The Okta API Client is an open source [Composer](https://getcomposer.org/) package for use in Laravel applications for connecting to Okta for provisioning and deprovisioning of users, groups, applications, and other related functionality.

Please use at your own risk and create pull requests for any bugs that you encounter.

### Problem Statement

Instead of providing an SDK method for every endpoint in the API documentation, we have taken a simpler approach by providing a universal `ApiClient` that can perform `GET`, `POST`, `PUT`, and `DELETE` requests to any endpoint that you find in the [Okta API documentation](https://developer.okta.com/docs/reference/core-okta-api/).

This builds upon the simplicity of the [Laravel HTTP Client](https://laravel.com/docs/10.x/http-client) that is powered by the [Guzzle HTTP client](http://docs.guzzlephp.org/en/stable/) to provide "last lines of code parsing" for Okta API responses to improve the developer experience.

The value of this API Client is that it handles the API request logging, response pagination, rate limit backoff, and 4xx/5xx exception handling for you.

### Example Usage

```php
use BoldlyGrow\Okta\ApiClient;

// Get a list of records
// https://developer.okta.com/docs/reference/api/groups/#list-groups
$groups = ApiClient::get('groups');

// Search for records with a specific name
// This example uses positional arguments
// https://developer.okta.com/docs/reference/core-okta-api/#filter
// https://developer.okta.com/docs/reference/api/groups/#list-groups-with-search
$groups = ApiClient::get('groups', [
    'search' => 'profile.name eq "Hack the Planet Engineers"'
]);

// Search for users with a specific
// This example uses positional arguments
// https://developer.okta.com/docs/reference/api/users/#list-users-with-search
$users = ApiClient::get('users', [
    'search' => 'profile.firstName eq "Dade"'
]);

// Get a specific record
// https://developer.okta.com/docs/reference/api/groups/#get-group
$group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');

// {
//     +"id": "0og1ab2c3D4E5F6G7h8i",
//     +"created": "2023-01-01T00:00:00.000Z",
//     +"lastUpdated": "2023-02-01T00:00:00.000Z",
//     +"lastMembershipUpdated": "2023-03-15T00:00:00.000Z",
//     +"type": "OKTA_GROUP",
//     +"profile": {
//         +"name": "Hack the Planet Engineers",
//         +"description": "This group contains engineers that have proven they are elite enough to hack the Gibson.",
//     },
// }

$group_name = $group->data->profile->name;
// Hack the Planet Engineers

// Create a group
// https://developer.okta.com/docs/reference/api/groups/#add-group
// This example uses named arguments
$group = ApiClient::post(
    uri: 'groups',
    data: [
        'profile' => [
            'name' => 'Hack the Planet Engineers',
            'description' => 'This group contains engineers that have proven they are elite enough to hack the Gibson.'
        ]
    ]
);

// Update a group
// https://developer.okta.com/docs/reference/api/groups/#update-group
// This example uses named arguments
$group_id = '00g1ab2c3D4E5F6G7h8i';
$group = ApiClient::put(
    uri: 'groups/' . $group_id,
    data: [
        'profile' => [
            'description' => 'This group contains engineers that have liberated the garbage files.'
        ]
    ]
);

// Delete a group
// https://developer.okta.com/docs/reference/api/groups/#remove-group
$group_id = '00g1ab2c3D4E5F6G7h8i';
ApiClient::delete('groups/' . $group_id);
```

### Issue Tracking and Bug Reports

We do not maintain a roadmap of feature requests, however we invite you to contribute and we will gladly review your pull requests.

Please create an [issue](https://github.com/boldlygrow/okta-api-client/issues) for bug reports.

### Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) to learn more about how to contribute.

### Maintainers

| Name | GitHub Handle | Email |
|------|---------------|-------|
| [Jeff Martin](https://www.linkedin.com/in/jeffersonmmartin/) | [@jeffersonmartin](https://github.com/jeffersonmartin) | `jeff [at] boldlygrow [dot] us` |

### Contributor Credit

- Dillon Wheeler
- Jeff Martin

## Installation

### Requirements

| Requirement | Version                     |
|-------------|-----------------------------|
| PHP         | `^8.2` (up to 8.5 tested)   |
| Laravel     | `^12.0`, `^13.0`            |

OAuth 2.0 authentication requires no additional Composer packages; the JWT client assertion is signed with the `openssl` extension. This package does not integrate with any secrets manager, so there is no cloud SDK dependency. If you use a secrets manager, your platform or your own application code loads the signing key from it (see [Private Key Storage](#private-key-storage)).

### Upgrade Guide

See the [changelog](CHANGELOG.md) for release notes. If you are upgrading from 5.x, read the [6.0 release notes](CHANGELOG.md#600---2026-10-31) for the supported Laravel versions. If you are upgrading from `provisionesta/okta-api-client` (4.x), read the [5.0 release notes](CHANGELOG.md#500---2026-10-31) for the package rename, namespace change, and OAuth 2.0 authentication options.

### Add Composer Package

```plain
composer require boldlygrow/okta-api-client:^5.0
```

If you are contributing to this package, see [CONTRIBUTING.md](CONTRIBUTING.md) for instructions on configuring a local composer package with symlinks.

### Publish the configuration file

**This is optional**. The configuration file specifies which `.env` variable names that that the API connection is stored in. You only need to publish the configuration file if you want to rename the `OKTA_API_*` `.env` variable names.

```plain
php artisan vendor:publish --tag=okta-api-client
```

## Authentication

The client supports two authentication methods. It uses OAuth 2.0 automatically when an OAuth `client_id` is configured, and otherwise falls back to a legacy SSWS API token. New integrations should use OAuth 2.0.

You don't need to call any authentication endpoints in your application. When you send a `GET`, `POST`, `PATCH`, `PUT`, or `DELETE` request to an Okta API endpoint with `ApiClient`, the client gets an access token (or uses your SSWS token), caches it, and sends it with the request for you.

| Setup | Credential | Best for |
|-------|------------|----------|
| OAuth 2.0 with a key you generate | Client ID + a private key that only you have | Production |
| OAuth 2.0 with a key Okta generates | Client ID + a private key that Okta shows you once | Testing and proofs of concept |
| SSWS API token (legacy) | A static token that acts as an Okta admin user | Existing deployments, quick local testing |

Both OAuth 2.0 setups work the same way at runtime. The only difference is where the private key was created. Client secrets aren't supported (see [Why Client Secrets Aren't Supported](#why-client-secrets-arent-supported)).

The quick starts below cover the most common setups. For how each method works, other ways to generate a key, DPoP, token caching, and key rotation, see [Advanced Authentication](#advanced-authentication).

### Quick Start: OAuth 2.0

These steps create an Okta service app, generate a key pair with the package's Artisan command, and make your first request.

#### Step 1: Create the Service App

1. In the Okta Admin Console, go to **Applications > Applications** and click **Create App Integration**.
2. Choose the classic experience, then select **API Services** as the sign-in method and click **Next**.
3. For the app name, enter the name of your Laravel application, such as the product name that your users and Okta admins know it by. Click **Save**.
4. On the **General** tab, copy the **Client ID**. You will set this as `OKTA_API_CLIENT_ID`.

#### Step 2: Generate a Key Pair

The client signs each token request with an RSA private key that you keep, and Okta verifies the signature with the matching public key that you register. Generate the key pair with the package's Artisan command:

```plain
mkdir -p ~/.okta && chmod 700 ~/.okta
php artisan okta-api-client:jwk --generate --kid=acme-portal-production-2026-10 --out="$HOME/.okta/okta-private-key.pem"
```

- `--kid` is the key ID. It's required, and it can be any string you choose. Okta shows it in the app's **Public Keys** table, much like the name of a personal access token, so use a value that identifies the application, environment, and date. You can't change it later (see [Key ID (kid)](#key-id-kid)).
- `--out` is where the private key is written, with `0600` permissions. Keep it outside your repository, and never commit it.

The command prints the public key as a JSON Web Key (JWK), which you paste into Okta in the next step. Your `n` value will differ:

```json
{
    "kty": "RSA",
    "n": "0vx7agoebGcQSuu…",
    "e": "AQAB",
    "use": "sig",
    "alg": "RS256",
    "kid": "acme-portal-production-2026-10"
}
```

To let Okta generate the key pair instead, or to use OpenSSL, `ssh-keygen`, or PHP, see [Key Generation Options](#key-generation-options).

#### Step 3: Register the Public Key in Okta

1. In the service app, locate the **Public Keys** section and click **Edit** in the top right corner.
2. Click **Add key**. Paste the entire JWK JSON, and click **Done**. Click the **Save** button.
    - It is common to experience parsing errors if you are self-generating keys due to expected formats. Use your LLM with redacted characters in your keys to debug any parsing errors.
3. In the service app, open the **General** tab. In the **Client Credentials** section, click **Edit**.
4. For **Client authentication**, select **Public key / Private key**. Okta doesn't allow this until a key exists in the **Public Keys** section, which is why you add the key first.
5. Click **Save**.
6. Check that the **Public Keys** table shows your key with the `kid` that you chose. You will set this as `OKTA_API_KEY_ID`.

On the same tab, check whether **Require Demonstrating Proof of Possession (DPoP) header in token requests** is selected. We recommend leaving it on. If it's not on, you will set `OKTA_API_DPOP=false` (see [Demonstrating Proof of Possession](#demonstrating-proof-of-possession)).

#### Step 4: Grant Scopes and an Admin Role

The service app needs both scopes and admin permissions. Scopes control which API endpoints the token is allowed to call. The permissions in the app's admin role authorize the actions on those endpoints, such as reading users or updating groups. A request with a granted scope but without a matching permission fails with a `403`.

1. On the **Okta API Scopes** tab, click **Grant** for each scope that your integration needs, for example `okta.users.read` and `okta.groups.read`.
2. On the **Admin roles** tab, assign an admin role whose permissions match the scopes that you granted:
    - **Custom admin role (recommended):** Only the permissions that your scopes need, such as viewing users and groups, limited to a resource set.
    - **Read-only Administrator:** An acceptable risk. It can view everything in your org, but can't change anything.
    - **Super Administrator:** Don't assign it. A leaked key would have full control of your Okta org.

See [Scopes and Admin Roles](#scopes-and-admin-roles) for how to create a custom admin role.

#### Step 5: Set the Environment Variables

Add the connection variables to your `.env` file:

```php
OKTA_API_URL="https://mycompany.okta.com"
OKTA_API_CLIENT_ID="0oaB1c2D3e5F6g7H8i9j"
OKTA_API_KEY_ID="acme-portal-production-2026-10"
OKTA_API_PRIVATE_KEY_PATH="~/.okta/okta-private-key.pem"

# If the service app does not use DPoP (see Step 3)
OKTA_API_DPOP=false
```

A file path works well for local development. In production, store the private key in a secrets manager and load it at runtime (see [Private Key Storage](#private-key-storage)).

#### Step 6: Make a Request

Pass the scope that each request needs as the `scope` argument. The client gets and caches the access token for you.

```php
use BoldlyGrow\Okta\ApiClient;

$users = ApiClient::get(
    uri: 'users',
    scope: 'okta.users.read'
)->data;
```

If the request fails, see [Troubleshooting Authentication](#troubleshooting-authentication).

### Quick Start: SSWS API Token (Legacy)

SSWS tokens are what this package used before it supported OAuth 2.0, and they're still supported. An SSWS token acts as the Okta admin user who created it, with all of that user's permissions (see [How SSWS Tokens Work](#how-ssws-tokens-work)). **Be careful not to use your Super Admin account to generate the API key. Create a new user that acts as a service account instead.**

> New integrations should use OAuth 2.0 instead. See the Okta documentation for [creating an API token](https://developer.okta.com/docs/guides/create-an-api-token/main/).

1. Create a dedicated service account (bot) user for the integration, so that the token isn't tied to a person.
2. Assign the user a least-privilege admin role, such as a custom admin role or `Read-only Administrator`. Don't assign `Super Administrator`.
3. Sign in to the Okta Admin Console as the service account user. Go to **Security > API**, open the **Tokens** tab, and click **Create token**.
4. Enter a name for the token, then copy its value. Okta shows the token only once.
5. Add the connection variables to your `.env` file. The client adds the `SSWS ` prefix to the token, so don't include it.

    ```php
    OKTA_API_URL="https://mycompany.okta.com"
    OKTA_API_TOKEN="00mBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789AbCd"
    ```

6. Make requests without a `scope` argument. It's ignored for SSWS tokens.

    ```php
    use BoldlyGrow\Okta\ApiClient;

    $users = ApiClient::get(uri: 'users')->data;
    ```

Okta revokes a token after 30 days without API calls. To move to OAuth 2.0 later, see [Moving from SSWS to OAuth 2.0](#moving-from-ssws-to-oauth-20).

### Environment Variables

The client reads these variables from your environment or `.env` file through the `config/okta-api-client.php` configuration. If you use a secrets manager, have your platform load the client ID and private key into these variables at runtime (see [Load Secrets Into Environment Variables](#load-secrets-into-environment-variables)). If the credentials vary per tenant or are stored in your database, pass a [connection array](#connection-arrays) on each request.

#### OAuth 2.0 Variables

| Variable | Description |
|----------|-------------|
| `OKTA_API_URL` | **Required.** Your Okta org URL (see [URL](#url)). |
| `OKTA_API_CLIENT_ID` | **Required.** The service app's client ID. When it's set, the client uses OAuth 2.0 and ignores `OKTA_API_TOKEN`. |
| `OKTA_API_KEY_ID` | **Required.** The `kid` of the registered public key (see [Key ID (kid)](#key-id-kid)). |
| `OKTA_API_PRIVATE_KEY_PATH` | **Option 1.** The path to the private key file. A leading `~` is expanded to your home directory. |
| `OKTA_API_PRIVATE_KEY` | **Option 2.** The private key as an inline PEM string. Set this or `OKTA_API_PRIVATE_KEY_PATH`. This takes precedence if both are set. |
| `OKTA_API_TEST_URI` | (Optional) The endpoint that `testConnection()` calls. Defaults to `org`, which needs the `okta.orgs.read` scope. |
| `OKTA_API_DPOP` | (Optional) Set to `false` if your service app does not use Demonstrating Proof of Possession (DPoP) on the Okta application configuration. Defaults to `true`. |
| `OKTA_API_TOKEN_CACHE_MINUTES` | (Optional) How long to cache each access token, from `1` to `59` minutes. Defaults to `59` (see [Token Caching](#token-caching)). |
| `OKTA_API_EXCEPTIONS` | (Optional) Whether to throw exceptions for `4xx` and `5xx` responses. Defaults to `true`. |

There is no scope variable. Each request passes its own `scope` argument (see [Scopes and Admin Roles](#scopes-and-admin-roles)).

#### SSWS API Token Variables (Legacy)

| Variable | Description |
|----------|-------------|
| `OKTA_API_URL` | **Required.** Your Okta org URL (see [URL](#url)). |
| `OKTA_API_TOKEN` | **Required.** The API token, without the `SSWS ` prefix. |
| `OKTA_API_EXCEPTIONS` | (Optional) Whether to throw exceptions for `4xx` and `5xx` responses. Defaults to `true`. |
| `OKTA_API_TEST_URI` | (Optional) The endpoint that `testConnection()` calls. Defaults to `org`. |

Leave `OKTA_API_CLIENT_ID` unset. When it's set, the client uses OAuth 2.0 and ignores `OKTA_API_TOKEN`.

#### URL

Each Okta customer is provided with a subdomain for their company. This is sometimes referred to as a tenant or `${yourOktaDomain}` in the API documentation. You can also use an Okta Preview instance.

If you're just getting started, it is recommended to use a free [Okta developer account](https://developer.okta.com/signup/).

```php
OKTA_API_URL="https://mycompany.okta.com"

OKTA_API_URL="https://mycompany.oktapreview.com"

OKTA_API_URL="https://dev-12345678.okta.com"
```

### Connection Arrays

The variables that you define in your `.env` file are used by default unless you set the connection argument with an array. A connection array contains the `url` and either an OAuth `client_id` (with the key ID and key location fields) or a legacy `token`.

> **Security Warning:** Do not commit a hard coded API token or private key into your code base. Only use connection arrays with values that you load at runtime, such as per-tenant credentials from your database or secrets manager.

#### OAuth 2.0 connection array

```php
$connection = [
    'url' => 'https://mycompany.okta.com',
    'client_id' => '0oaB1c2D3e5F6g7H8i9j',
    'key_id' => 'acme-portal-production-2026-10', // required
    'private_key' => $private_key_pem, // an inline PEM string (see Private Key Storage)
    // 'private_key_path' => '/var/secrets/okta/private-key.pem',
    'dpop' => false, // only if the service app doesn't use DPoP, defaults to true (see Demonstrating Proof of Possession)
];
```

The scope is not part of the connection array. It is passed per request as the `scope` argument.

#### Legacy SSWS connection array

```php
$connection = [
    'url' => 'https://mycompany.okta.com',
    'token' => '00mBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789AbCd',
];
```

Passing a connection array per request is the recommended pattern for multi-tenant applications, where each tenant has its own `client_id` and key. The OAuth token cache is keyed per connection and scope set, so tenants never share a token. If the whole application uses one service app, load its secrets into [environment variables](#load-secrets-into-environment-variables) instead.

```php
use BoldlyGrow\Okta\ApiClient;

class MyClass
{
    private array $connection;

    public function __construct($connection)
    {
        $this->connection = $connection;
    }

    public function getGroup($group_id)
    {
        return ApiClient::get(
            connection: $this->connection,
            uri: 'groups/' . $group_id,
            scope: 'okta.groups.read'
        )->data;
    }
}
```

### Security Best Practices

#### Least Privilege

Grant the service app only the scopes that it needs, and assign an admin role whose permissions match those scopes, preferably a custom admin role (see [Scopes and Admin Roles](#scopes-and-admin-roles)). The app's granted scopes and admin role are the real least-privilege boundary. Requesting narrower scopes per call is defense in depth on top of that grant, not a replacement for it.

#### No Shared Credentials

Do not reuse an OAuth app or API token created for another purpose. Create a dedicated service app (or token) per use case so that revoking a compromised credential does not affect unrelated systems.

#### Credential Storage

Do not hard code your API token or private key in any `config/*.php` files that are committed to your repository (secret leak).

In production, store the private key in a secrets manager or vault and resolve it at runtime rather than committing it or baking it into an image. This package does not fetch the key for you. Have your platform load it into `OKTA_API_PRIVATE_KEY` at runtime, or mount it as a file for `OKTA_API_PRIVATE_KEY_PATH` (see [Private Key Storage](#private-key-storage)). For local development, keep the key file outside the repository. All `.env` values should remain in the `.gitignore`-excluded `.env` file.

#### Rotation

Rotate private keys and API tokens on a schedule, and whenever someone who had access to them leaves. Service apps support more than one public key, so you can rotate a key without downtime (see [Key Rotation](#key-rotation)).

### Troubleshooting Authentication

| Error message | Likely cause | Fix |
|---------------|--------------|-----|
| `The key_id (OKTA_API_KEY_ID) is required.` | `OKTA_API_KEY_ID` isn't set, or the connection array has no `key_id`. | Set it to the `kid` in the app's **Public Keys** table (see [Key ID (kid)](#key-id-kid)). |
| `The resolved private key is empty or is not a valid PEM-formatted private key.` | The key is in the OpenSSH format, has a passphrase, is a public key, or lost its line breaks in an inline value. | Check the key's first line against [Private Key Format](#private-key-format). |
| `The private key file was not found or is not readable at (...)` | The path is wrong, or the user that runs PHP can't read the file. | Check the path in the message. A `~` is only expanded at the start of the path. |
| `The token_cache_minutes (OKTA_API_TOKEN_CACHE_MINUTES) value must be a whole number between 1 and 59` | `OKTA_API_TOKEN_CACHE_MINUTES` is `0`, more than `59`, or not a number. | Set it to a whole number from `1` to `59`, or remove it to use the default. |
| `Okta API OAuth token request error.` with `The Okta service app requires DPoP.` | The app requires DPoP, but `OKTA_API_DPOP` or the connection array `dpop` is set to `false`. | Remove the setting, or set it to `true`. DPoP is enabled by default. |
| `Okta API OAuth token request error.` with another reason | The client ID is wrong, the public key isn't registered, the `kid` doesn't match, client authentication isn't set to **Public key / Private key**, or a requested scope isn't granted. | Compare `OKTA_API_KEY_ID` with the **Public Keys** table, and check that each scope you request is granted on the **Okta API Scopes** tab. |
| `An OAuth scope is required but was not provided.` | OAuth is configured, but the request has no `scope` argument. | Add the `scope` argument to the request (see [Scopes and Admin Roles](#scopes-and-admin-roles)). |
| `The OAuth access token was rejected.` | Okta returned a `401` for the access token, for example because it was revoked or the app's grants changed. | The client has already removed the token from the cache, so the next request mints a new one. If the error persists, check the app's granted scopes. |
| `403` with `E0000006 You do not have permission to perform the requested action` | The scope is granted, but the app's admin role doesn't include a permission for the action. | Add the permission to the app's admin role (see [Scopes and Admin Roles](#scopes-and-admin-roles)). |
| `invalid_dpop_proof 'iat' claim in the DPoP proof JWT is invalid.` | The server's clock is out of sync. | Sync the system clock with NTP. |
| `The SSWS API token was rejected.` | The token expired after 30 days of inactivity, was revoked, or belongs to a deactivated user. | Create a new token (see [Quick Start: SSWS API Token (Legacy)](#quick-start-ssws-api-token-legacy)). |

## API Requests

You can make an API request to any of the resource endpoints in the [Okta REST API Documentation](https://developer.okta.com/docs/reference/core-okta-api/).

**Just getting started?** Explore the [applications](https://developer.okta.com/docs/reference/api/apps), [groups](https://developer.okta.com/docs/reference/api/groups/), and [users](https://developer.okta.com/docs/reference/api/users/) endpoints.

| Endpoint              | API Documentation                                                                                                              |
|-----------------------|--------------------------------------------------------------------------------------------------------------------------------|
| `apps`                | [List applications](https://developer.okta.com/docs/reference/api/apps/#list-applications)                                     |
| `apps/{id}`           | [Get application](https://developer.okta.com/docs/reference/api/apps/#get-application)                                         |
| `apps/{id}/users`     | [List users assigned to application](https://developer.okta.com/docs/reference/api/apps/#list-users-assigned-to-application)   |
| `apps/{id}/groups`    | [List groups assigned to application](https://developer.okta.com/docs/reference/api/apps/#list-groups-assigned-to-application) |
| `groups`              | [List groups](https://developer.okta.com/docs/reference/api/groups/#list-groups)                                               |
| `groups/{id}`         | [Get group](https://developer.okta.com/docs/reference/api/groups/#get-group)                                                   |
| `groups/{id}/users`   | [List group members](https://developer.okta.com/docs/reference/api/groups/#list-group-members)                                 |
| `users`               | [List users](https://developer.okta.com/docs/reference/api/users/#list-users)                                                  |
| `users/{id}`          | [Get user](https://developer.okta.com/docs/reference/api/users/#get-user)                                                      |
| `users/{id}/appLinks` | [Get applications assigned to user](https://developer.okta.com/docs/reference/api/users/#get-assigned-app-links)               |

### Dependency Injection

If you include the fully-qualified namespace at the top of of each class, you can use the class name inside the method where you are making an API call.

```php
use BoldlyGrow\Okta\ApiClient;

class MyClass
{
    public function getGroup($group_id)
    {
        return ApiClient::get('groups/' . $group_id)->data;
    }
}
```

If you do not use dependency injection, you need to provide the fully qualified namespace when using the class.

```php
class MyClass
{
    public function getGroup($group_id)
    {
        return \BoldlyGrow\Okta\ApiClient::get('groups/' . $group_id)->data;
    }
}
```

### Class Instantiation

We transitioned to using static methods in v4.0 and you do not need to instantiate the ApiClient class.

```php
ApiClient::get('groups');
ApiClient::post('groups', []);
ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');
ApiClient::put('groups/00g1ab2c3D4E5F6G7h8i', []);
ApiClient::delete('groups/00g1ab2c3D4E5F6G7h8i');
```

### Named vs Positional Arguments

You can use named arguments/parameters (introduced in PHP 8) or positional function arguments/parameters.

It is recommended is to use named arguments if you are specifying request data and/or are using a connection array. You can use positional arguments if you are only specifying the URI.

Learn more in the PHP documentation for [function arguments](https://www.php.net/manual/en/functions.arguments.php), [named parameters](https://php.watch/versions/8.0/named-parameters), and this helpful [blog article](https://stitcher.io/blog/php-8-named-arguments).

```php
// Named Arguments
ApiClient::get(
    uri: 'groups'
);

// Positional Arguments
ApiClient::get('groups');
```

### GET Requests

The endpoint starts without a leading `/` after `/api/v1/`. The Okta API documentation provides the full endpoint, so remove the `/api/v1/` when copy and pasting the endpoint.

See the [List all groups](https://developer.okta.com/docs/reference/api/groups/#list-groups) API documentation as reference for the examples below.

With the API Client, you use the `get()` method with the endpoint `groups` as the `uri` argument.

```php
ApiClient::get('groups');
```

You can also use variables or database models to get data for constructing your endpoints.

```php
// Get a list of records
// https://developer.okta.com/docs/reference/api/groups/#list-groups
$records = ApiClient::get('groups');

// Use variable for endpoint
$endpoint = 'groups';
$records = ApiClient::get($endpoint);

// Get a specific record
// https://developer.okta.com/docs/reference/api/groups/#get-group
$group_id = '0og1ab2c3D4E5F6G7h8i';
$record = ApiClient::get('groups/' . $group_id);

// Get a specific record using a variable
// This assumes that you have a database column named `api_group_id` that
// contains the string with the Okta ID `0og1ab2c3D4E5F6G7h8i`.
$okta_group = \App\Models\OktaGroup::where('id', $id)->firstOrFail();
$record = ApiClient::get('groups/' . $okta_group->api_group_id);
```

#### GET Requests with Query String Parameters

The second positional argument or `data` named argument of a `get()` method is an optional array of parameters that is parsed by the API Client and the [Laravel HTTP Client](https://laravel.com/docs/10.x/http-client#get-request-query-parameters) and rendered as a query string with the `?` and `&` added automatically.

##### API Request Filtering

The Okta API uses `profile` child arrays for several resources. Most metadata that you define for a user or group will be in the profile. When searching for values, you use dot notation (ex. `profile.name`) to access to these attributes. Learn more in the [filter](https://developer.okta.com/docs/reference/core-okta-api/#filter) documentation. You will see references to `filter` and `search`, however it is recommended to use `search` for all queries.

##### API Response Filtering

You can also use [Laravel Collections](https://laravel.com/docs/10.x/collections#available-methods) to filter and transform results, either using a full data set or one that you already filtered with your API request.

See [Using Laravel Collections](#using-laravel-collections) to learn more.

##### Search for Records with Specific Name

> https://developer.okta.com/docs/reference/api/groups/#list-groups-with-search

```php
// Named Arguments
$records = ApiClient::get(
    uri: 'groups',
    data: ['search' => 'profile.name eq "Hack the Planet Engineers"']
);

// Positional Arguments
$records = ApiClient::get('groups', [
    'search' => 'profile.name eq "Hack the Planet Engineers"'
]);

// This will parse the array and render the query string
// https://mycompany.okta.com/api/v1/groups?search=profile.name+eq+%22Hack%20the&%20Planet%20Engineers%22
```

##### List all deprovisioned users

> https://developer.okta.com/docs/reference/api/users/#list-users-with-search

```php
$records = ApiClient::get(
    uri: 'users',
    data: ['search' => 'status eq "DEPROVISIONED"']
);

// This will parse the array and render the query string
// https://mycompany.okta.com/api/v1/groups?search=status+eq+%22DEPROVISIONED%22
```

##### List all users in a specific department

> https://developer.okta.com/docs/reference/api/users/#list-users-with-search

```php
$records = ApiClient::get(
    uri: 'users',
    data: ['search' => 'profile.department eq "Engineering"']
);

// This will parse the array and render the query string
// https://mycompany.okta.com/api/v1/groups?search=profile.department+eq+%22Engineering%22
```

### POST Requests

The `post()` method works almost identically to a `get()` request with an array of parameters, however the parameters are passed as form data using the `application/json` content type rather than in the URL as a query string. This is industry standard and not specific to the API Client.

You can learn more about request data in the [Laravel HTTP Client documentation](https://laravel.com/docs/10.x/http-client#request-data).

```php
// Create a group
// https://developer.okta.com/docs/reference/api/groups/#add-group
$record = ApiClient::post(
    uri: 'groups',
    data: [
        'profile' => [
            'name' => 'Hack the Planet Engineers',
            'description' => 'This group contains engineers that have proven they are elite enough to hack the Gibson.'
        ]
    ]
);
```

### PATCH Requests

> Partial updates are not supported on all endpoints. For example, they are supported on the users endpoint, but not on the groups endpoint. For endpoints that don't support partial updates, you will need to provide all of the attributes (ex. the entire profile). This may require fetching the record and overriding the value of the specific key in the array and passing the entire array back to the API client `data` argument.

The `patch()` method is used for updating one or more attributes on existing records. A patch is used for partial updates. If you want to update and replace the attributes for the **entire** existing record, you should use the [put() method](#put-requests).

You need to ensure that the ID of the record that you want to update is provided in the first argument (URI). In most applications, this will be a variable that you get from your database or another location and won't be hard-coded.

```php
// Update a group
// https://developer.okta.com/docs/reference/api/groups/#update-group
$group_id = '00g1ab2c3D4E5F6G7h8i';
$record = ApiClient::put(
    uri: 'groups/' . $group_id,
    data: [
        'profile' => [
            'description' => 'This group contains engineers that have liberated the garbage files.'
        ]
    ]
);
```

> **Internal Developer Note:** The Okta API does not support PATCH requests and uses non-standard POST requests for partial updates. The `patch()` method is used in the Okta API Client for improved developer experience, and we use the Laravel HTTP Client `post()` method behind the scenes. You can use the `post()` method in the Okta API Client for updating records without any issues, this is just an overlay to comply with industry conventions for using `PATCH`.

### PUT Requests

The `put()` method is used for updating and replacing the attributes for an **entire** existing record. If you want to update one or more attributes **without updating the entire existing record**, use the [patch() method](#patch-requests). For most use cases, you will want to use the `patch()` method to update records.

You need to ensure that the ID of the record that you want to update is provided in the first argument (URI). In most applications, this will be a variable that you get from your database or another location and won't be hard-coded.

```php
// Update a group
// https://developer.okta.com/docs/reference/api/groups/#update-group
$group_id = '00g1ab2c3D4E5F6G7h8i';
$record = ApiClient::put(
    uri: 'groups/' . $group_id,
    data: [
        'profile' => [
            'name' => 'Hack the Planet Engineers',
            'description' => 'This group contains engineers that have revealed to the world their elite skills.'
        ]
    ]
);
```

### DELETE Requests

The `delete()` method is used for methods that will destroy the resource based on the ID that you provide.

Keep in mind that `delete()` methods will return different status codes depending on the vendor (ex. 200, 201, 202, 204, etc). Okta's API will return a `204` status code for successfully deleted resources. You should use the `$response->status->successful` boolean for checking results.

```php
// Delete a group
// https://developer.okta.com/docs/reference/api/groups/#remove-group
$group_id = '00g1ab2c3D4E5F6G7h8i';
$record = ApiClient::delete('groups/' . $group_id);
```

### Class Methods

The examples above show basic inline usage that is suitable for most use cases. If you prefer to use classes and constructors, the example below will be helpful.

```php
<?php

use BoldlyGrow\Okta\ApiClient;
use BoldlyGrow\Okta\Exceptions\NotFoundException;

class OktaGroupService
{
    private $connection;

    public function __construct(array $connection = [])
    {
        // If connection is null, use the environment variables
        $this->connection = !empty($connection) ? $connection : config('okta-api-client');
    }

    public function listGroups($query = [])
    {
        $groups = ApiClient::get(
            connection: $this->connection,
            uri: 'groups',
            data: $query
        );

        return $groups->data;
    }

    public function getGroup($id, $query = [])
    {
        try {
            $group = ApiClient::get(
                connection: $this->connection,
                uri: 'groups/' . $id,
                data: $query
            );
        } catch (NotFoundException $e) {
            // Custom logic to handle a record not found. For example, you could
            // redirect to a page and flash an alert message.
        }

        return $group->data;
    }

    public function storeGroup($request_data)
    {
        $group = ApiClient::post(
            connection: $this->connection,
            uri: 'groups',
            data: $request_data
        );

        // To return an object with the newly created group
        return $group->data;

        // To return the ID of the newly created group
        // return $group->data->id;

        // To return the status code of the form request
        // return $group->status->code;

        // To return a bool with the status of the form request
        // return $group->status->successful;

        // To throw an exception if the request fails
        // throw_if(!$group->status->successful, new \Exception($group->error->message, $group->status->code));

        // To return the entire API response with the data, headers, and status
        // return $group;
    }

    public function updateGroup($id, $request_data)
    {
        try {
            $group = ApiClient::put(
                connection: $this->connection,
                uri: 'groups/' . $id,
                data: $request_data
            );
        } catch (NotFoundException $e) {
            // Custom logic to handle a record not found. For example, you could
            // redirect to a page and flash an alert message.
        }

        // To return an object with the updated group
        return $group->data;

        // To return a bool with the status of the form request
        // return $group->status->successful;
    }

    public function deleteGroup($id)
    {
        try {
            $group = ApiClient::delete(
                connection: $this->connection,
                uri: 'groups/' . $id
            );
        } catch (NotFoundException $e) {
            // Custom logic to handle a record not found. For example, you could
            // redirect to a page and flash an alert message.
        }

        return $group->status->successful;
    }
}
```

### Rate Limits

Most rate limits are hit due to pagination with large responses (ex. `/users` endpoint). If you have a large dataset, you may want to consider using `search` query to filter results to a smaller number of results.

In v4.0, we added automatic backoff when 20% of rate limit is remaining. This slows down the requests by implementing a `sleep(10)` with each request. Since the rate limit resets at 60 seconds, this will slow the next 5-6 requests until the rate limit resets.

If the Okta rate limit is exceeded for an endpoint, a `BoldlyGrow\Okta\Exceptions\RateLimitException` will be thrown.

The backoff will slow the requests, however if the rate limit is exceeded, the request will fail and terminate.

## API Responses

This API Client uses the BoldlyGrow standards for API response formatting.

```php
// API Request
$group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');

// API Response
$group->data; // object
$group->headers; // array
$group->status; // object
$group->status->code; // int (ex. 200)
$group->status->ok; // bool (is 200 status)
$group->status->successful; // bool (is 2xx status)
$group->status->failed; // bool (is 4xx/5xx status)
$group->status->clientError; // bool (is 4xx status)
$group->status->serverError; // bool (is 5xx status)
```

### Response Data

The `data` property contains the contents of the Laravel HTTP Client `object()` method that has been parsed and has the final merged output of any paginated results.

```php
$group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');
$group->data;
```

```json
{
    +"id": "0og1ab2c3D4E5F6G7h8i",
    +"created": "2023-01-01T00:00:00.000Z",
    +"lastUpdated": "2023-02-01T00:00:00.000Z",
    +"lastMembershipUpdated": "2023-03-15T00:00:00.000Z",
    +"type": "OKTA_GROUP",
    +"profile": {
        +"name": "Hack the Planet Engineers",
        +"description": "This group contains engineers that have proven they are elite enough to hack the Gibson.",
    },
}
```

#### Access a single record value

You can access these variables using object notation. This is the most common use case for handling API responses.

```php
$group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i')->data;

$group_name = $group->profile->name;
// Hack the Planet Engineers
```

#### Looping through records

If you have an array of multiple objects, you can loop through the records. The API Client automatically paginates and merges the array of records for improved developer experience.

```php
$groups = ApiClient::get('groups')->data;

foreach($groups as $group) {
    dd($group->profile->name);
    // Hack the Planet Engineers
}
```

#### Caching responses

The API Client does not use caching to avoid any constraints with you being able to control which endpoints you cache.

You can wrap an endpoint in a cache facade when making an API call. You can learn more in the [Laravel Cache](https://laravel.com/docs/10.x/cache) documentation.

```php
use Illuminate\Support\Facades\Cache;
use BoldlyGrow\Okta\ApiClient;

$groups = Cache::remember('okta_groups', now()->addHours(12), function () {
    return ApiClient::get('groups')->data;
});

foreach($groups as $group) {
    dd($group->profile->name);
    // Hack the Planet Engineers
}
```

When getting a specific ID or passing additional arguments, be sure to pass variables into `use($var1, $var2)`.

```php
$group_id = '00g1ab2c3D4E5F6G7h8i';

$groups = Cache::remember('okta_group_' . $group_id, now()->addHours(12), function () use ($group_id) {
    return ApiClient::get('groups/' . $group_id)->data;
});
```

#### Date Formatting

You can use the [Carbon](https://carbon.nesbot.com/docs/) library for formatting dates and performing calculations.

```php
$created_date = Carbon::parse($group->data->created)->format('Y-m-d');
// 2023-01-01
```

```php
$created_age_days = Carbon::parse($group->data->created)->diffInDays();
// 265
```

#### Using Laravel Collections

You can use [Laravel Collections](https://laravel.com/docs/10.x/collections#available-methods) which are powerful array helper tools that are similar to array searching and SQL queries that you may already be familiar with.

See the [Parsing Responses with Laravel Collections](#parsing-responses-with-laravel-collections) documentation to learn more.

### Response Headers

> The headers are returned as an array instead of an object since the keys use hyphens that conflict with the syntax of accessing keys and values easily.

```php
$group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');
$group->headers;
```

```php
[
    "Date" => "Sun, 30 Jan 2022 01:11:44 GMT",
    "Content-Type" => "application/json",
    "Transfer-Encoding" => "chunked",
    "Connection" => "keep-alive",
    "Server" => "nginx",
    "Public-Key-Pins-Report-Only" => "pin-sha256="REDACTED="; pin-sha256="REDACTED="; pin-sha256="REDACTED="; pin-sha256="REDACTED="; max-age=60; report-uri="https://okta.report-uri.com/r/default/hpkp/reportOnly"",
    "Vary" => "Accept-Encoding",
    "x-okta-request-id" => "A1b2C3D4e5@f6G7H8I9j0k1L2M3",
    "x-xss-protection" => "0",
    "p3p" => "CP="HONK"",
    "x-rate-limit-limit" => "1000",
    "x-rate-limit-remaining" => "998",
    "x-rate-limit-reset" => "1643505155",
    "cache-control" => "no-cache, no-store",
    "pragma" => "no-cache",
    "expires" => "0",
    "content-security-policy" => "default-src 'self' mycompany.okta.com *.oktacdn.com; connect-src 'self' mycompany.okta.com mycompany-admin.okta.com *.oktacdn.com *.mixpanel.com *.mapbox.com app.pendo.io data.pendo.io pendo-static-5634101834153984.storage.googleapis.com mycompany.kerberos.okta.com https://oinmanager.okta.com data:; script-src 'unsafe-inline' 'unsafe-eval' 'self' mycompany.okta.com *.oktacdn.com; style-src 'unsafe-inline' 'self' mycompany.okta.com *.oktacdn.com app.pendo.io cdn.pendo.io pendo-static-5634101834153984.storage.googleapis.com; frame-src 'self' mycompany.okta.com mycompany-admin.okta.com login.okta.com; img-src 'self' mycompany.okta.com *.oktacdn.com *.tiles.mapbox.com *.mapbox.com app.pendo.io data.pendo.io cdn.pendo.io pendo-static-5634101834153984.storage.googleapis.com data: blob:; font-src 'self' mycompany.okta.com data: *.oktacdn.com fonts.gstatic.com",
    "expect-ct" => "report-uri="https://oktaexpectct.report-uri.com/r/t/ct/reportOnly", max-age=0",
    "x-content-type-options" => "nosniff",
    "Strict-Transport-Security" => "max-age=315360000; includeSubDomains",
    "set-cookie" => "sid=""; Expires=Thu, 01-Jan-1970 00:00:10 GMT; Path=/ autolaunch_triggered=""; Expires=Thu, 01-Jan-1970 00:00:10 GMT; Path=/ JSESSIONID=E07ED763D2ADBB01B387772B9FB46EBF; Path=/; Secure; HttpOnly"
]
```

#### Getting a Header Value

```php
$content_type = $group->headers['Content-Type'];
// application/json
```

### Response Status

See the [Laravel HTTP Client documentation](https://laravel.com/docs/10.x/http-client#error-handling) to learn more about the different status booleans.

```php
$group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');
$group->status;
```

```php
{
  +"code": 200 // int (ex. 200)
  +"ok": true // bool (is 200 status)
  +"successful": true // bool (is 2xx status)
  +"failed": false // bool (is 4xx/5xx status)
  +"serverError": false // bool (is 4xx status)
  +"clientError": false // bool (is 5xx status)
}
```

#### API Response Status Code

```php
$group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');

$status_code = $group->status->code;
// 200
```

## Error Responses

An exception is thrown for any 4xx or 5xx responses. All responses are automatically logged.

### Exceptions

| Code      | Exception Class                                          |
|-----------|----------------------------------------------------------|
| 400       | `BoldlyGrow\Okta\Exceptions\BadRequestException`         |
| 401       | `BoldlyGrow\Okta\Exceptions\UnauthorizedException`       |
| 403       | `BoldlyGrow\Okta\Exceptions\ForbiddenException`          |
| 404       | `BoldlyGrow\Okta\Exceptions\NotFoundException`           |
| 405       | `BoldlyGrow\Okta\Exceptions\MethodNotAllowedException`   |
| 409       | `BoldlyGrow\Okta\Exceptions\ConflictException`           |
| 412       | `BoldlyGrow\Okta\Exceptions\PreconditionFailedException` |
| 422       | `BoldlyGrow\Okta\Exceptions\UnprocessableException`      |
| 429       | `BoldlyGrow\Okta\Exceptions\RateLimitException`          |
| 500       | `BoldlyGrow\Okta\Exceptions\ServerErrorException`        |
| 503       | `BoldlyGrow\Okta\Exceptions\ServiceUnavailableException` |
| Other 4xx | `BoldlyGrow\Okta\Exceptions\BadRequestException`         |
| Other 5xx | `BoldlyGrow\Okta\Exceptions\ServerErrorException`        |

### Exception Messages

Every exception message contains the HTTP method, status code, and full URL of the request, followed by the reason that the Okta API returned for the failure.

```php
GET 404 https://example.okta.com/api/v1/users/00u1ab2c3D4E5F6G7h8i (Reason) E0000007 Not found: Resource not found: 00u1ab2c3D4E5F6G7h8i (User)
```

When a request fails validation, the `errorSummary` only names the object that failed. The per-attribute reasons from `errorCauses` are appended so that the actionable part of the response is not lost.

```php
// {"errorCode":"E0000001","errorSummary":"Api validation failed: profile","errorCauses":[{"errorSummary":"firstName: The field cannot be left blank"},{"errorSummary":"email: Does not match required pattern"}]}
POST 400 https://example.okta.com/api/v1/users (Reason) E0000001 Api validation failed: profile (Causes) firstName: The field cannot be left blank, email: Does not match required pattern
```

The OAuth 2.0 token endpoints return `error` and `error_description` instead of `errorCode` and `errorSummary`. Both formats are handled.

```php
// {"error":"invalid_client","error_description":"The client secret is invalid."}
POST 400 https://example.okta.com/oauth2/v1/token (Reason) invalid_client The client secret is invalid.
```

If the response body uses none of these keys, the body itself is appended (truncated to 1000 characters) so that the reason is never discarded. If the response body is empty, the message contains only the method, status code, and URL.

Percent encoded URLs are hard to read, so a decoded copy of the URL is appended when it differs from the URL that was requested. The encoded URL is always shown first and is the one to reproduce the request with, because decoding a `%2F` produces a URL that the API routes to a different endpoint.

```php
GET 400 https://example.okta.com/api/v1/users?filter=profile.email+eq+%22user%40example.com%22 (Reason) E0000031 Invalid filter (Decoded) https://example.okta.com/api/v1/users?filter=profile.email eq "user@example.com"
```

The same reason is stored as `errors.error_message` in the audit log entry for any unsuccessful request.

### Catching Exceptions

You can catch any exceptions that you want to handle silently. Any uncaught exceptions will appear for users and cause 500 errors that will appear in your monitoring software.

```php
use BoldlyGrow\Okta\Exceptions\NotFoundException;

try {
    $group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');
} catch (NotFoundException $e) {
    // Group is not found. You can create a log entry, throw an exception, or handle it another way.
    Log::error('Okta group could not be found', ['okta_group_id' => $group_id]);
}
```

### Disabling Exceptions

If you do not want exceptions to be thrown, you can globally disable exceptions for the Okta API Client and handle the status for each request yourself. Simply set the `OKTA_API_EXCEPTIONS=false` in your `.env` file.

```php
OKTA_API_EXCEPTIONS=false
```

## Parsing Responses with Laravel Collections

You can use [Laravel Collections](https://laravel.com/docs/10.x/collections#available-methods) which are powerful array helper tools that are similar to array searching and SQL queries that you may already be familiar with.

```php
$users = ApiClient::get('users');

$user_collection = collect($users->data)->where('profile.department', 'Security')->toArray();

// This will return an array of users that belong to the Security department based on their profile attribute
```

For syntax conventions and readability, you can easily collapse this into a single line. Since the ApiClient automatically handles any 4xx or 5xx error handling, you do not need to worry about try/catch exceptions.

```php
$users = collect(ApiClient::get('users')->data)
    ->where('profile.department', 'Security')
    ->toArray();
```

This approach allows you to have the same benefits as if you were doing a SQL query and will feel familiar as you start using collections.

```sql
SELECT * FROM users WHERE department='Security';
```

### Collection Methods

The most common methods that are useful for filtering data are:

| Laravel Docs                                                              | Usage Example                         |
|---------------------------------------------------------------------------|---------------------------------------|
| [count](https://laravel.com/docs/10.x/collections#method-count)           | [Usage Example](#count-methods)       |
| [countBy](https://laravel.com/docs/10.x/collections#method-countBy)       | [Usage Example](#count-methods)       |
| [except](https://laravel.com/docs/10.x/collections#method-except)         | N/A                                   |
| [filter](https://laravel.com/docs/10.x/collections#method-filter)         | N/A                                   |
| [flip](https://laravel.com/docs/10.x/collections#method-flip)             | N/A                                   |
| [groupBy](https://laravel.com/docs/10.x/collections#method-groupBy)       | [Usage Example](#group-method)        |
| [keyBy](https://laravel.com/docs/10.x/collections#method-keyBy)           | N/A                                   |
| [only](https://laravel.com/docs/10.x/collections#method-only)             | N/A                                   |
| [pluck](https://laravel.com/docs/10.x/collections#method-pluck)           | [Usage Example](#pluck-method)        |
| [sort](https://laravel.com/docs/10.x/collections#method-sort)             | [Usage Example](#sort-methods)        |
| [sortBy](https://laravel.com/docs/10.x/collections#method-sortBy)         | [Usage Example](#sort-methods)        |
| [sortKeys](https://laravel.com/docs/10.x/collections#method-sortKeys)     | [Usage Example](#sort-methods)        |
| [toArray](https://laravel.com/docs/10.x/collections#method-toArray)       | N/A                                   |
| [transform](https://laravel.com/docs/9.x/collections#method-transform)    | [Usage Example](#transforming-arrays) |
| [unique](https://laravel.com/docs/10.x/collections#method-unique)         | [Usage Example](#unique-method)       |
| [values](https://laravel.com/docs/10.x/collections#method-values)         | [Usage Example](#values-method)       |
| [where](https://laravel.com/docs/10.x/collections#method-where)           | N/A                                   |
| [whereIn](https://laravel.com/docs/10.x/collections#method-whereIn)       | N/A                                   |
| [whereNotIn](https://laravel.com/docs/10.x/collections#method-whereNotIn) | N/A                                   |

### Collection Simplified Arrays

#### Pluck Method

You can use collections to get a specific attribute using the [pluck](https://laravel.com/docs/10.x/collections#method-pluck) method.

```php
// Get an array with email addresses
$user_job_titles = collect(ApiClient::get('users')->data)
    ->pluck('profile.email')
    ->toArray();

// [
//     0 => 'mspinka@example.com',
//     1 => 'rferry@example.com',
//     2 => 'sconnelly@example.com',
// ]
```

You can also use the [pluck](https://laravel.com/docs/10.x/collections#method-pluck) method to get two attributes and set one as the array key and the other as the array value.

```php
// Get an array with email address keys and job title values
$user_job_titles = collect(ApiClient::get('users')->data)
    ->pluck('profile.title', 'profile.email')
    ->toArray();

// [
//     'rferry@example.com' => 'Senior Frontend Engineer',
//     'mspinka@example.com' => 'Professional Services Engineer',
//     'sconnelly@example.com' => 'Frontend Engineer',
// ]
```

#### Unique Method

You can use the [unique](https://laravel.com/docs/10.x/collections#method-unique) method to get a list of unique attribute values (ex. job title).

Each method can be daisy chained and is evaluated one-at-a-time in the order shown.

Although programatically it might sound more efficient to find unique array values and then parse them, you have flexibility with collections to handle it however you'd like for readability. The speed improvement and memory footprint is marginal (<10%) and since this is usually handled as a background job, it is recommended to focus on human readability and personal preference.

```php
// Get an array of unique job titles

// Option 1
$unique_job_titles = collect(ApiClient::get('users')->data)
    ->unique('profile.title')
    ->pluck('profile.title')
    ->toArray();

// Option 2 (marginally faster)
$unique_job_titles = collect(ApiClient::get('users')->data)
    ->pluck('profile.title')
    ->unique()
    ->toArray();

// [
//     236 => 'Professional Services Engineer',
//     511 => 'Senior Frontend Engineer',
//     988 => 'Frontend Engineer',
// ]
```

#### Values Method

When using the `unique` method, it is using the key of the user record that it found. You should add [values](https://laravel.com/docs/10.x/collections#method-values) method near the end to reset all of the key integers based on the number of results that you have.

```php
// Get an array of unique job titles

// Option 1
$unique_job_titles = collect(ApiClient::get('users')->data)
    ->unique('profile.title')
    ->pluck('profile.title')
    ->values()
    ->toArray();

// Option 2
$unique_job_titles = collect(ApiClient::get('users')->data)
    ->pluck('profile.title')
    ->unique()
    ->values()
    ->toArray();

// [
//     0 => 'Professional Services Engineer',
//     1 => 'Senior Frontend Engineer',
//     2 => 'Frontend Engineer',
// ]
```

#### Sort Methods

You can alphabetically sort by an attribute value. Simply provide the attribute to [sortBy](https://laravel.com/docs/10.x/collections#method-sortBy) method (nested array values are supported). If you have already used the pluck method and the array value is a string, you can use [sort](https://laravel.com/docs/10.x/collections#method-sort) which doesn't accept an argument.

```php
// Get an array of unique job titles

// Option 1
$unique_job_titles = collect(ApiClient::get('users')->data)
    ->sortBy('profile.title')
    ->unique('profile.title')
    ->pluck('profile.title')
    ->values()
    ->toArray();

// Option 2
$unique_job_titles = collect(ApiClient::get('users')->data)
    ->pluck('profile.title')
    ->unique()
    ->sort()
    ->values()
    ->toArray();

// [
//     0 => 'Frontend Engineer',
//     1 => 'Professional Services Engineer',
//     2 => 'Senior Frontend Engineer',
// ]
```

If you have array key strings, you can use the [sortKeys](https://laravel.com/docs/10.x/collections#method-sortKeys) method to sort the resulting array keys alphabetically.

```php
// Get an array with email address keys and job title values
$user_job_titles = collect(ApiClient::get('users')->data)
    ->pluck('profile.title', 'profile.email')
    ->sortKeys()
    ->toArray();

// [
//     'mspinka@example.com' => 'Professional Services Engineer',
//     'rferry@example.com' => 'Senior Frontend Engineer',
//     'sconnelly@example.com' => 'Frontend Engineer',
// ]
```

#### Count Methods

You can use the [count](https://laravel.com/docs/10.x/collections#method-count) method to get a count of the total number of results after all methods have been applied. This is used as an alternative to [toArray](https://laravel.com/docs/10.x/collections#method-toArray) so you get an integer value instead of needing to do a `count($collection_array)`.

```php
// Get a count of unique job titles
$unique_job_titles = collect(ApiClient::get('users')->data)
    ->pluck('profile.title')
    ->unique()
    ->count();

// 376
```

You can use the [countBy](https://laravel.com/docs/10.x/collections#method-countBy) method to get a count of unique attribute values. You should use the [sortKeys](https://laravel.com/docs/10.x/collections#method-sortKeys) method to sort the resulting array keys alphabetically.

```php
// Get a count of unique job titles
$unique_job_titles = collect(ApiClient::get('users')->data)
    ->countBy('profile.title')
    ->sortKeys()
    ->toArray();

// [
//     'Frontend Engineer' => 8,
//     'Professional Services Engineer' => 4,
//     'Senior Frontend Engineer' => 44,
// ]
```

### Transforming Arrays

When working with a record returned from the API, you will have a lot of data that you don't need for the current use case.

#### Raw Response

```php
// Disclaimer: This is anonymized fake data.
[
    {
      +"id": "00ue2xov9e5xiQmuL5d7",
      +"status": "ACTIVE",
      +"created": "2023-12-23T16:49:49.000Z",
      +"activated": "2023-12-23T16:49:50.000Z",
      +"statusChanged": "2023-12-23T16:49:50.000Z",
      +"lastLogin": null,
      +"lastUpdated": "2023-12-23T16:49:50.000Z",
      +"passwordChanged": "2023-12-23T16:49:50.000Z",
      +"type": {
        +"id": "otye2ebqn49728Yfb5d7",
      },
      +"profile": {
        +"lastName": "Howe",
        +"costCenter": "Sales",
        +"displayName": "Angelica Howe",
        +"secondEmail": null,
        +"managerId": "5f9632",
        +"hire_date": "2020-12-19",
        +"title": "Senior Channel Sales Manager",
        +"login": "ahowe@example.com",
        +"employeeNumber": "aee562",
        +"division": "Sales",
        +"firstName": "Angelica",
        +"management_level": "Individual Contributor",
        +"mobilePhone": null,
        +"department": "Channel Sales",
        +"email": "ahowe@example.com",
      },
    },
    {
      +"id": "00ue2xp1yybaQEE2o5d7",
      +"status": "ACTIVE",
      +"created": "2023-12-23T16:49:12.000Z",
      +"activated": "2023-12-23T16:49:12.000Z",
      +"statusChanged": "2023-12-23T16:49:12.000Z",
      +"lastLogin": null,
      +"lastUpdated": "2023-12-23T16:49:12.000Z",
      +"passwordChanged": "2023-12-23T16:49:12.000Z",
      +"type": {
        +"id": "otye2ebqn49728Yfb5d7",
      },
      +"profile": {
        +"lastName": "O'Kon",
        +"costCenter": "Sales",
        +"displayName": "Earlene O'Kon",
        +"secondEmail": null,
        +"managerId": "2410f0",
        +"hire_date": "2019-03-01",
        +"title": "Manager, Deal Desk",
        +"login": "eo'kon@example.com",
        +"employeeNumber": "0561bc",
        +"division": "Sales",
        +"firstName": "Earlene",
        +"management_level": "Manager",
        +"mobilePhone": null,
        +"department": "Sales Operations",
        +"email": "eo'kon@example.com",
      },
    },
  ]
```

#### Basic Transformations

You can use the [transform](https://laravel.com/docs/10.x/collections#method-transform) method to perform a foreach loop over each record and create a new array with the specific fields that you want.

You can think of the `$item` variable as `foreach($users as $item) { }` that has all of the metadata for a specific record.

The transform method uses a function (a.k.a. closure) to return an array that should become the new value for this specific array key.

```php
// Get all Okta users
$users = collect(ApiClient::get('users')->data)
    ->transform(function($item) {
        return [
            'id' => $item->id,
            'displayName' => $item->profile->displayName,
            'email' => $item->profile->email,
            'title' => $item->profile->title,
            'department' => $item->profile->department
        ];
    })->toArray();

// [
//     "id" => "00ue2xov9e5xiQmuL5d7",
//     "displayName" => "Angelica Howe",
//     "email" => "ahowe@example.com",
//     "title" => "Senior Channel Sales Manager",
//     "department" => "Channel Sales",
// ],
// [
//     "id" => "00ue2xp1yybaQEE2o5d7",
//     "displayName" => "Earlene O'Kon",
//     "email" => "eo'kon@example.com",
//     "title" => "Manager, Deal Desk",
//     "department" => "Sales Operations",
// ],
```

##### Checking If Attributes Exist

When working with the transform method, you do need to check if values exist using [isset](https://www.php.net/manual/en/function.isset.php) or set them to null for fields that not every record will have. You will have additional debugging problems if you are using [null coalescing operators](https://www.php.net/manual/en/migration70.new-features.php#migration70.new-features.null-coalesce-op), so it is recommended to stick with `isset()`. It is best practice to use [ternary operators](https://www.phptutorial.net/php-tutorial/php-ternary-operator/) for consistent syntax.

```php
$users = collect(ApiClient::get('users')->data)
    ->transform(function($item) {
        return [
            'id' => $item->id,
            'displayName' => $item->profile->displayName,
            'email' => $item->profile->email,
            'title' => isset($item->profile->title) ? $item->profile->title : null,
            'department' => isset($item->profile->department) ? $item->profile->department : null
        ];
    })->toArray();
```

##### Arrow Functions

If all of your transformations can be done in-line in the array and don't require defining additional variables (see [Advanced Transformations](#advanced-transformations)), you can use the shorthand arrow functions. This is a personal preference and not a requirement.

```php
$users = collect(ApiClient::get('users')->data)
    ->transform(fn($item) => [
        'id' => $item->id,
        'displayName' => $item->profile->displayName,
        'email' => $item->profile->email,
        'title' => isset($item->profile->title) ? $item->profile->title : null,
        'department' => isset($item->profile->department) ? $item->profile->department : null
    ])->toArray();
```

#### Advanced Transformations

You can also perform additional calculations in the transform function before passing the value to the array. This provides you the most flexibility, freedom, and power to do whatever you need to do.

It is up to you whether to define variables or perform the calculations inline.

```php
use Carbon\Carbon;

$users = collect(ApiClient::get('users')->data)
    ->transform(function($item) {
        // Calculate dates using Carbon (https://carbon.nesbot.com/docs/)
        $created_date = Carbon::parse($item->created)->format('Y-m-d');
        $created_date_age = Carbon::parse($item->created)->diffInDays();

        // It is recommended to use match statements instead of if/else statements for string matching use cases
        $elevated_permissions = match($item->profile->department) {
            'Infrastructure' => true,
            'IT' => true,
            'Security' => true,
            default => false
        };

        return [
            'id' => $item->id,
            'displayName' => $item->profile->displayName,
            'email' => $item->profile->email,
            'title' => isset($item->profile->title) ? $item->profile->title : null,
            'department' => isset($item->profile->department) ? $item->profile->department : null,
            'created_date' => $created_date,
            'new_user' => ($created_date_age < 60 ? true : false),
            'elevated_permissions' => $elevated_permissions
        ];
    })->toArray();

// [
//     "id" => "00ue2xov9e5xiQmuL5d7",
//     "displayName" => "Angelica Howe",
//     "email" => "ahowe@example.com",
//     "title" => "Senior Channel Sales Manager",
//     "department" => "Channel Sales",
//     "created_date" => "2023-12-23",
//     "new_user" => true,
//     "elevated_permissions" => false,
// ],
// [
//     "id" => "00ue2xp1yybaQEE2o5d7",
//     "displayName" => "Earlene O'Kon",
//     "email" => "eo'kon@example.com",
//     "title" => "Manager, Deal Desk",
//     "department" => "Sales Operations",
//     "created_date" => "2023-12-23",
//     "new_user" => true,
//     "elevated_permissions" => false,
// ],
```

#### Group Method

Although you can use a [groupBy](https://laravel.com/docs/10.x/collections#method-groupBy) method with a raw response, it is very difficult to manipulate the data once it's grouped, so it is recommended to transform your data and then add the `groupBy('attribute_name')` to the end of your collection chain. Keep in mind that you renamed your array value keys (attributes) when you transformed the data so you want to use the new array key. In the example, we defined a new `department` attribute and `profile.department` is no longer accessible.

```php
$users = collect(ApiClient::get('users')->data)
    ->transform(fn($item) => [
        'id' => $item->id,
        'displayName' => $item->profile->displayName,
        'email' => $item->profile->email,
        'title' => isset($item->profile->title) ? $item->profile->title : null,
        'department' => isset($item->profile->department) ? $item->profile->department : null
    ])->groupBy('department')
    ->toArray();

// "Channel Sales" => [
//     [
//         "id" => "00ue2xov9e5xiQmuL5d7",
//         "displayName" => "Angelica Howe",
//         "email" => "ahowe@example.com",
//         "title" => "Senior Channel Sales Manager",
//         "department" => "Channel Sales",
//     ],
// ],
// "Sales Operations" => [
//     [
//         "id" => "00ue2xp1yybaQEE2o5d7",
//         "displayName" => "Earlene O'Kon",
//         "email" => "eo'kon@example.com",
//         "title" => "Manager, Deal Desk",
//         "department" => "Sales Operations",
//     ],
//     [
//         "id" => "00ue2xpoh6h5rfN315d7",
//         "displayName" => "Rylee Veum",
//         "email" => "rveum@example.com",
//         "title" => "Senior Program Manager, Customer Programs",
//         "department" => "Sales Operations",
//     ],
// ],
```

### Additional Reading

See the [Laravel Collections](https://laravel.com/docs/10.x/collections) documentation for additional usage.

## Advanced Authentication

This section explains how authentication works behind the scenes, and covers other ways to generate and store keys. You don't need it to get started (see [Authentication](#authentication)), but it helps when you review the security model, automate key management, or troubleshoot an error.

### How SSWS Tokens Work

An SSWS token is a static secret that an Okta admin creates in the Admin Console. The client sends it unchanged on every request:

```plain
Authorization: SSWS {token}
```

- **Permissions:** The token acts as the admin user who created it, with all of that user's admin roles. There are no scopes, so the `scope` argument is ignored.
- **Lifetime:** The token has no fixed expiry. Okta revokes it after 30 days without API calls, or when the user who created it is deactivated.
- **Exposure:** Anyone who has the token can use it until it is revoked. Nothing else is needed to prove who is calling.
- **Validation:** Before sending a request, the client checks that the token is 42 characters long, using only letters, numbers, `-`, and `_`. Okta API tokens are 42 characters.

### How OAuth 2.0 Works

The Okta service app has a public key registered in Okta, and the client has the matching private key. Instead of sending a secret, the client signs a short-lived JWT (the client assertion) with the private key. The private key never leaves your application.

```mermaid
sequenceDiagram
    participant App as Your application
    participant Client as ApiClient
    participant Token as Okta token endpoint
    participant API as Okta API

    App->>Client: API request with a scope
    alt No cached token for this connection and scope
        Client->>Client: Sign a client assertion with the private key
        Client->>Token: POST client_credentials grant with the client assertion
        Token-->>Client: Access token that expires in 60 minutes
        Client->>Client: Encrypt and cache the token
    end
    Client->>API: Request with the access token
    API-->>Client: Response
    Client-->>App: Parsed response
```

The first time a scope is requested, the client gets an access token:

1. **Resolve the private key.** The client reads `private_key` (an inline PEM string) or the file at `private_key_path`, then checks that PHP's OpenSSL extension can parse it (see [Private Key Format](#private-key-format)).
2. **Sign a client assertion.** The client builds a JWT and signs it with the private key using RS256:

    | Part | Field | Value |
    |------|-------|-------|
    | Header | `alg` | `RS256` |
    | Header | `kid` | `OKTA_API_KEY_ID`. Required, since Okta rejects an assertion without it (see [Key ID (kid)](#key-id-kid)). |
    | Claims | `iss` and `sub` | The service app's client ID |
    | Claims | `aud` | `https://{yourOktaDomain}/oauth2/v1/token` |
    | Claims | `iat` and `exp` | The current time, and 5 minutes later |
    | Claims | `jti` | A random UUID, so that the assertion can't be replayed |

3. **Request a token.** The client posts the assertion to the org authorization server's `/oauth2/v1/token` endpoint, with `grant_type=client_credentials` and the scope from the request.
4. **Okta verifies the assertion.** Okta finds the registered public key that matches the `kid`, then verifies the signature and claims and checks that each requested scope is granted to the app. If everything is valid, it returns an access token that expires in 60 minutes.
5. **Cache the token.** The token is encrypted and cached for 59 minutes by default (see [Token Caching](#token-caching)).
6. **Call the API.** The token is sent as `Authorization: Bearer {token}` on this request and on later requests for the same scope. Okta then checks that the app's admin role permits the action (see [Scopes and Admin Roles](#scopes-and-admin-roles)).

When DPoP is enabled, the token request and each API request also carry a signed DPoP proof, and the token is sent as `Authorization: DPoP {token}` (see [Demonstrating Proof of Possession](#demonstrating-proof-of-possession)).

### Key ID (kid)

Every public key that is registered on a service app has a key ID (`kid`). The client sends it in the header of each client assertion so that Okta knows which public key to verify the signature with. It's required. Okta rejects a client assertion without a `kid`, so the client throws a `ConfigurationException` before requesting a token if `OKTA_API_KEY_ID` isn't set.

The `kid` is set when the key is created, and it can be any string you choose. Okta shows it in the app's **Public Keys** table, much like the name of a personal access token, so choose a value that describes what the key is for. For example, `acme-portal-production-2026-10` identifies the application, environment, and month that the key was created. Use a different `kid` for each key on an app.

You can't change a key's `kid` after it's registered. To use a different one, register a new key and retire the old one (see [Key Rotation](#key-rotation)).

Where the `kid` comes from depends on how you create the key:

| Key generation method | Where the `kid` comes from |
|-----------------------|----------------------------|
| Okta generates the key | Okta assigns it. |
| Artisan command | The `--kid` option. If you leave it out, the command uses the key's RFC 7638 thumbprint, a 43-character hash. |
| OpenSSL or `ssh-keygen` | A PEM file doesn't have a `kid`. You add one when you convert the public key to a JWK with `php artisan okta-api-client:jwk okta-public-key.pem --kid=...`. |
| PHP | The `kid` argument of `PublicKeyJwk::fromPem()` or `PublicKeyJwk::fromPemFile()`, or the thumbprint if you leave it out. |

In every case, set `OKTA_API_KEY_ID` to the `kid` that's shown in the **Public Keys** table.

### Key Generation Options

You need two things: the private key in PEM format, which you keep, and the public key as a JSON Web Key (JWK), which you register in Okta. Any of the following methods produces a key that the client accepts. After you generate a key pair yourself, register it as described in [Step 3](#step-3-register-the-public-key-in-okta) of the quick start.

Use a new key pair for each Okta integration. Don't reuse a key that you use for SSH or another service.

#### Okta-Generated vs Self-Generated Keys

Both options produce an RSA key pair, and the client uses the private key in exactly the same way. The difference is who creates the private key and who has seen it.

| | Okta-generated | Self-generated |
|-|----------------|----------------|
| Who creates the key pair | Okta, in the Admin Console | You, with the Artisan command, OpenSSL, `ssh-keygen`, or PHP |
| Who has seen the private key | Okta and your browser. Okta shows it once and does not store it. | Only you |
| Public key registration | Okta adds the public key to the app | You paste the public key into Okta as a JWK |
| Key ID (`kid`) | Assigned by Okta | Chosen by you |
| Recommended for | Testing | Production |

You can switch between the two without changing code. Add the new key to the app, update the private key and `OKTA_API_KEY_ID`, and then retire the old key (see [Key Rotation](#key-rotation)).

#### Let Okta Generate the Key Pair

Okta recommends this option for testing only, because Okta generated (and briefly held) the private key. For production, generate the key yourself so that the private key never leaves your control.

1. In the service app, open the **General** tab. In the **Client Credentials** section, click **Edit**.
2. In the **Public Keys** section, click **Add key**, then **Generate new key**.
3. Okta shows the new key pair. Click **PEM** to view the private key in PEM format, then **Copy to clipboard**. The client only reads PEM, so don't copy the JWK version. This is the **only** time Okta shows the private key, so save it now (see below). Okta does not store it.
4. Click **Done**.
5. For **Client authentication**, select **Public key / Private key**, then click **Save**. Okta doesn't allow this until a key exists in the **Public Keys** section.
6. In the **Public Keys** table, copy the **Key ID (KID)** that Okta assigned. Set `OKTA_API_KEY_ID` to that value.

To save the copied key to a file on macOS, paste it from the clipboard into a file that only you can read:

```plain
mkdir -p ~/.okta && chmod 700 ~/.okta
pbpaste > ~/.okta/okta-private-key.pem
chmod 600 ~/.okta/okta-private-key.pem
```

On Linux, paste the key into `~/.okta/okta-private-key.pem` with a text editor, and then run the same `chmod` commands. The first line of the file should start with `-----BEGIN` and end with `PRIVATE KEY-----`.

Then continue with [Step 4](#step-4-grant-scopes-and-an-admin-role) of the quick start.

#### Using the Artisan Command

The `okta-api-client:jwk` command generates a key pair, or converts an existing public key to a JWK. It only needs PHP's `openssl` extension.

| Option | Description |
|--------|-------------|
| `--generate` | Generate a new key pair instead of converting a public key file. |
| `--out` | Where to write the private key when using `--generate`. Defaults to `okta-private-key.pem` in the current directory. |
| `--kid` | The key ID. Defaults to the key's RFC 7638 thumbprint. |
| `--bits` | The key size when using `--generate`. Defaults to `2048`, which is also the minimum. |
| `path` | The public key PEM file to convert, when not using `--generate`. |

```plain
# Generate a new key pair
php artisan okta-api-client:jwk --generate --kid=acme-portal-production-2026-10 --out="$HOME/.okta/okta-private-key.pem"

# Convert an existing public key to a JWK
php artisan okta-api-client:jwk okta-public-key.pem --kid=acme-portal-production-2026-10
```

The private key is written with `0600` permissions. Your shell doesn't expand `~` after `--out=`, so use `$HOME` or a full path. Never commit the private key.

#### Using OpenSSL on macOS or Linux

```plain
# Create a directory that only you can read
mkdir -p ~/.okta && chmod 700 ~/.okta

# Generate a 2048-bit RSA private key
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out ~/.okta/okta-private-key.pem
chmod 600 ~/.okta/okta-private-key.pem

# Extract the public key
openssl pkey -in ~/.okta/okta-private-key.pem -pubout -out ~/.okta/okta-public-key.pem

# Convert the public key to a JWK to paste into Okta
php artisan okta-api-client:jwk ~/.okta/okta-public-key.pem --kid=acme-portal-production-2026-10
```

These commands work with OpenSSL 3 (most Linux distributions and Homebrew) and with the LibreSSL that ships with macOS at `/usr/bin/openssl`. Run `openssl version` to check which one you have. The private key's first line is `-----BEGIN PRIVATE KEY-----`.

Older guides use `openssl genrsa -out okta-private-key.pem 2048`. This also works, but the output format depends on your OpenSSL version: OpenSSL 3 writes `BEGIN PRIVATE KEY`, and LibreSSL writes `BEGIN RSA PRIVATE KEY`. The client accepts both.

The public key isn't secret. After you register it in Okta, you can delete `okta-public-key.pem` or keep it for reference.

#### Using ssh-keygen

If you prefer `ssh-keygen`, you must pass `-m PEM`. Without it, `ssh-keygen` writes the OpenSSH format (`-----BEGIN OPENSSH PRIVATE KEY-----`), which PHP's OpenSSL extension can't read.

```plain
mkdir -p ~/.okta && chmod 700 ~/.okta

# -m PEM writes a key that PHP can read, and -N "" leaves it without a passphrase
ssh-keygen -t rsa -b 2048 -m PEM -N "" -C "" -f ~/.okta/okta-private-key.pem

# Extract the public key in PEM format, then convert it to a JWK
openssl pkey -in ~/.okta/okta-private-key.pem -pubout -out ~/.okta/okta-public-key.pem
php artisan okta-api-client:jwk ~/.okta/okta-public-key.pem --kid=acme-portal-production-2026-10
```

The private key's first line is `-----BEGIN RSA PRIVATE KEY-----`. `ssh-keygen` also writes `okta-private-key.pem.pub`, which is an OpenSSH public key that the JWK command can't read. Use the `openssl pkey` command above instead, and delete the `.pub` file.

If you already generated an Okta key in the OpenSSH format, convert a copy of it. The key itself doesn't change, so a public key that you already registered in Okta still matches.

```plain
cp ~/.okta/okta-key ~/.okta/okta-private-key.pem
ssh-keygen -p -m PKCS8 -P "" -N "" -f ~/.okta/okta-private-key.pem
```

The copy's first line changes to `-----BEGIN PRIVATE KEY-----`. If the key has a passphrase, leave out `-P ""` and enter the passphrase when prompted. The `-N ""` option removes it from the copy, since the client doesn't support passphrase-protected keys.

#### Using PHP in Your Laravel Application

You can generate the key pair in PHP, for example in a key rotation job or a provisioning command. This uses the same `openssl` functions as the Artisan command, so no additional packages are needed:

```php
use BoldlyGrow\Okta\PublicKeyJwk;

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
]);

// A "-----BEGIN PRIVATE KEY-----" string to store in your secrets manager
openssl_pkey_export($key, $private_key_pem);

// The JWK to register in the Okta service app
$public_key_pem = openssl_pkey_get_details($key)['key'];
$jwk = PublicKeyJwk::fromPem($public_key_pem, kid: 'acme-portal-production-2026-10');

echo json_encode($jwk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

`openssl_pkey_new()` returns `false` if the `openssl` extension can't generate a key, so check the result in production code.

Store `$private_key_pem` in your secrets manager and load it at runtime (see [Private Key Storage](#private-key-storage)). For local development, you can write it to a file outside your repository instead:

```php
use Illuminate\Support\Facades\File;

$path = getenv('HOME') . '/.okta/okta-private-key.pem';

File::ensureDirectoryExists(dirname($path), 0700);
File::put($path, $private_key_pem);
File::chmod($path, 0600);
```

`PublicKeyJwk::fromPemFile()` also converts a public key file, if your key management process already writes one:

```php
$jwk = PublicKeyJwk::fromPemFile('okta-public-key.pem', kid: 'acme-portal-production-2026-10');
```

### Private Key Format

The client reads the private key with PHP's `openssl_pkey_get_private()`, so the key must be an RSA private key in PEM format without a passphrase. The file name and extension don't matter. You can check a key's format by looking at its first line:

| First line | Accepted | Created by |
|------------|----------|------------|
| `-----BEGIN PRIVATE KEY-----` | Yes | The Artisan command, `openssl genpkey`, `openssl genrsa` with OpenSSL 3, and `openssl_pkey_export()` |
| `-----BEGIN RSA PRIVATE KEY-----` | Yes | `ssh-keygen -m PEM`, and `openssl genrsa` with LibreSSL |
| `-----BEGIN OPENSSH PRIVATE KEY-----` | No | `ssh-keygen` without `-m PEM`. Convert it (see [Using ssh-keygen](#using-ssh-keygen)). |
| `-----BEGIN ENCRYPTED PRIVATE KEY-----` | No | A key with a passphrase. Remove the passphrase (see below). |
| `-----BEGIN PUBLIC KEY-----` | No | This is the public key. Register it in Okta as a JWK, and give the client the private key. |

A `BEGIN RSA PRIVATE KEY` key that has a `Proc-Type: 4,ENCRYPTED` line also has a passphrase and isn't accepted. To write a copy of a passphrase-protected key without the passphrase, run the following command and enter the passphrase when prompted:

```plain
openssl pkey -in encrypted-key.pem -out ~/.okta/okta-private-key.pem
chmod 600 ~/.okta/okta-private-key.pem
```

To set a key inline with `OKTA_API_PRIVATE_KEY` in a `.env` file, you can put it on a single line, replacing each line break with a literal `\n`. The client converts each `\n` back to a line break. On macOS, this command copies a key file to the clipboard in that format:

```plain
php -r 'echo str_replace("\n", "\\n", trim(file_get_contents($argv[1])));' ~/.okta/okta-private-key.pem | pbcopy
```

```php
OKTA_API_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC...\n-----END PRIVATE KEY-----"
```

### Private Key Storage

The private key is provided in one of two ways. This package does not integrate with any secrets manager; fetching the key from a vault or secrets manager is your application's responsibility, which keeps the package independent of your storage choice and free of cloud SDK dependencies.

| Field | Value | Use |
|-------|-------|-----|
| `private_key` (`OKTA_API_PRIVATE_KEY`) | An inline PEM string | Production (resolved from your secrets manager at runtime), tests, CI |
| `private_key_path` (`OKTA_API_PRIVATE_KEY_PATH`) | A path to a PEM file (leading `~` expanded) | Local development, or a secret mounted as a file |

Provide one or the other. `private_key` takes precedence when both are set.

There are three ways to load the client ID and private key from a secrets manager:

| Approach | Use when |
|----------|----------|
| [Environment variables](#load-secrets-into-environment-variables) | Recommended. Your platform loads the secrets into environment variables when the container or VM starts. |
| [Published config file](#load-secrets-in-the-published-config) | Your platform can't set environment variables from a secrets manager. The secrets are fetched for the whole application. |
| [Connection array](#load-secrets-in-a-connection-array) | The credentials vary per tenant or change at runtime. |

#### Load Secrets Into Environment Variables

Most applications run in a container or on a VM, where the platform can load secrets manager values into environment variables at runtime. The client reads `OKTA_API_CLIENT_ID` and `OKTA_API_PRIVATE_KEY` like any other environment variable, so no code is needed. The PEM can contain real line breaks or escaped `\n` characters.

Environment variables are set when the container or VM starts. After you rotate a secret, restart or redeploy the application to load the new value, and run `php artisan config:cache` again if you cache your config.

##### Amazon ECS With Parameter Store

> This is an inspirational example to see a potential approach. Use at your own risk.

Store the client ID as a `String` and the private key as a `SecureString`:

```plain
aws ssm put-parameter --name /okta/mycompany/client-id --type String --value 0oaExampleClientId
aws ssm put-parameter --name /okta/mycompany/private-key --type SecureString --value file://okta-private-key.pem
```

Reference the parameters in the `secrets` of your container definition. If the parameters are in a different Region than the task, use their full ARNs. The task execution role needs `ssm:GetParameters` on both parameters, and `kms:Decrypt` if the `SecureString` uses a customer managed KMS key.

```json
"secrets": [
    { "name": "OKTA_API_CLIENT_ID", "valueFrom": "/okta/mycompany/client-id" },
    { "name": "OKTA_API_PRIVATE_KEY", "valueFrom": "/okta/mycompany/private-key" }
]
```

##### Cloud Run With Secret Manager

> This is an inspirational example to see a potential approach. Use at your own risk.
>
Store the client ID and private key as two secrets:

```plain
printf '0oaExampleClientId' | gcloud secrets create okta-client-id --data-file=-
gcloud secrets create okta-private-key --data-file=okta-private-key.pem
```

Map the secrets to environment variables when you deploy. The service's service account needs the Secret Manager Secret Accessor role (`roles/secretmanager.secretAccessor`) on both secrets.

```plain
gcloud run deploy my-service --image IMAGE \
    --set-secrets=OKTA_API_CLIENT_ID=okta-client-id:latest,OKTA_API_PRIVATE_KEY=okta-private-key:latest
```

##### Other Platforms

Kubernetes, other container platforms, and VM startup scripts can set the same variables from a secrets manager. If your platform mounts secrets as files instead, set `OKTA_API_PRIVATE_KEY_PATH` to the mounted file (ex. `/var/secrets/okta/private-key.pem`).

#### Load Secrets in the Published Config

If your platform can't load secrets into environment variables, fetch them for the whole application in the published config file. Every `ApiClient` call then uses `config('okta-api-client.client_id')` and `config('okta-api-client.private_key')` by default.

```plain
php artisan vendor:publish --tag=okta-api-client
```

The examples below use the same Parameter Store parameters and Secret Manager secrets as [Load Secrets Into Environment Variables](#load-secrets-into-environment-variables). They show one way to approach it and aren't part of this package. This package doesn't depend on either SDK, so install the one you use in your application (`aws/aws-sdk-php` or `google/cloud-secret-manager`).

Config files run wherever your application boots, including Composer's `package:discover` script and CI. So each example only calls the secrets manager when its environment variable is set, and otherwise falls back to the `.env` values. These variables belong to your application, and this package doesn't read them.

##### AWS Systems Manager Parameter Store

> This is an inspirational example to see a potential approach. Use at your own risk.

Fetch the parameters at the top of `config/okta-api-client.php`, and use the variables for the `client_id` and `private_key` keys. Keep the rest of the published file as it is.

```php
<?php

use Aws\Ssm\SsmClient;

$client_id = env('OKTA_API_CLIENT_ID');
$private_key = env('OKTA_API_PRIVATE_KEY');

// Fetch from Parameter Store when a prefix is set (ex. OKTA_SSM_PREFIX=/okta/mycompany)
if ($prefix = env('OKTA_SSM_PREFIX')) {
    $ssm = new SsmClient([
        'region' => env('AWS_DEFAULT_REGION'),
        'version' => 'latest',
    ]);

    $result = $ssm->getParameters([
        'Names' => ["{$prefix}/client-id", "{$prefix}/private-key"],
        'WithDecryption' => true,
    ]);

    if (! empty($result['InvalidParameters'])) {
        throw new RuntimeException('Okta SSM parameters not found: ' . implode(', ', $result['InvalidParameters']));
    }

    $parameters = array_column($result['Parameters'], 'Value', 'Name');
    $client_id = $parameters["{$prefix}/client-id"];
    $private_key = $parameters["{$prefix}/private-key"];
}

return [
    // ...

    'client_id' => $client_id,

    // ...

    'private_key' => $private_key,
    'private_key_path' => env('OKTA_API_PRIVATE_KEY_PATH'),

    // ...
];
```

The SDK uses the default AWS credential chain, such as the IAM role of an EC2 instance, ECS task, or Lambda function. The role needs `ssm:GetParameters` on both parameters, and `kms:Decrypt` if the `SecureString` uses a customer managed KMS key.

##### Google Secret Manager

> This is an inspirational example to see a potential approach. Use at your own risk.

Fetch the secrets at the top of `config/okta-api-client.php`, and use the variables for the `client_id` and `private_key` keys. Keep the rest of the published file as it is.

```php
<?php

use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;

$client_id = env('OKTA_API_CLIENT_ID');
$private_key = env('OKTA_API_PRIVATE_KEY');

// Fetch from Secret Manager when a project is set (ex. OKTA_GCP_PROJECT=my-gcp-project)
if ($project = env('OKTA_GCP_PROJECT')) {
    $secrets = new SecretManagerServiceClient();

    $access = fn (string $secret): string => $secrets->accessSecretVersion(
        (new AccessSecretVersionRequest())->setName(
            SecretManagerServiceClient::secretVersionName($project, $secret, 'latest')
        )
    )->getPayload()->getData();

    $client_id = $access('okta-client-id');
    $private_key = $access('okta-private-key');
}

return [
    // ...

    'client_id' => $client_id,

    // ...

    'private_key' => $private_key,
    'private_key_path' => env('OKTA_API_PRIVATE_KEY_PATH'),

    // ...
];
```

The client uses Application Default Credentials, such as the service account attached to Cloud Run, GKE, or Compute Engine. The service account needs the Secret Manager Secret Accessor role (`roles/secretmanager.secretAccessor`) on both secrets.

##### Config Caching and Rotation

The trade-off of this approach is that the secrets are fetched whenever Laravel loads its configuration, not when the client makes a request. How often that happens depends on whether your environment caches the config:

- **Without `config:cache`:** The secrets manager is called every time the application boots, which includes each web request and each Artisan command. This adds latency to every request and counts against the provider's API quotas.
- **With `config:cache`:** The secrets are fetched once when you run `php artisan config:cache`, and written in plain text to `bootstrap/cache/config.php`. Run it when you deploy or when a container starts, not when you build an image, so that the key isn't baked into the image.
- **After you rotate a secret:** A cached config keeps the old value until you run `php artisan config:cache` again or redeploy. Without a cached config, the new value is used the next time the application boots. The `kid` changes with each new key, so update `OKTA_API_KEY_ID` at the same time, or store and fetch it the same way as the key.

When the new key reaches the config, the client mints a new access token on the next request, since the token cache key includes a fingerprint of the key (see [Token Caching](#token-caching)).

#### Load Secrets in a Connection Array

> This is an inspirational example to see a potential approach. Use at your own risk.

If the credentials vary per tenant or change at runtime, fetch them in your own code and pass them in a per-request connection array instead. Each tenant can supply its own client ID and key. The example below uses Google Secret Manager, but any source works.

```php
use BoldlyGrow\Okta\ApiClient;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;

// Fetch the PEM in your own code. Swap this for Vault, AWS Secrets Manager,
// a mounted file, an HTTP call, etc. Cache it as appropriate for your app.
$client = new SecretManagerServiceClient();
$name = $client->secretVersionName('my-gcp-project', 'okta-mycompany-private-key', 'latest');
$private_key_pem = $client->accessSecretVersion(
    (new AccessSecretVersionRequest())->setName($name)
)->getPayload()->getData();

$connection = [
    'url' => 'https://mycompany.okta.com',
    'client_id' => '0oaExampleClientId',
    'key_id' => 'acme-portal-production-2026-10',
    'private_key' => $private_key_pem,
];

$users = ApiClient::get(
    uri: 'users',
    scope: 'okta.users.read',
    connection: $connection
)->data;
```

### Scopes and Admin Roles

Two settings on the service app control what the client can do, and every request needs both:

- **Scopes** decide which API endpoints an access token is allowed to call. For example, `okta.users.read` allows `GET /api/v1/users`. You grant scopes on the app's **Okta API Scopes** tab, and each request asks for the scopes that it needs.
- **Admin role permissions** authorize the action itself, on the resources that the role covers. You assign a role on the app's **Admin roles** tab.

If a request has a granted scope but the admin role doesn't include a matching permission, Okta returns a `403`. Keep the two in step: if you grant `okta.users.read`, give the role permission to view users, and nothing broader.

#### Choosing an Admin Role

| Role | Risk | Use |
|------|------|-----|
| Custom admin role | Lowest. It only has the permissions and resources that you choose. | Recommended |
| Read-only Administrator | Acceptable. It can view everything in your org, but can't change anything. | When a custom role isn't practical for a read-only integration |
| Super Administrator | High. A leaked key would have full control of your Okta org. | Don't assign it |

To create a custom admin role:

1. In the Okta Admin Console, go to **Security > Administrators**.
2. On the **Roles** tab, create a role with only the permissions that match the scopes that you granted.
3. On the **Resources** tab, create a resource set with the users, groups, or apps that the integration works with.
4. In the service app, open the **Admin roles** tab, and assign the role with the resource set.

#### Requesting Scopes

The scope is provided per request as the `scope` argument. There is no scope default in config or the environment, so each OAuth call declares exactly the authority it needs. A requested scope can never exceed what's granted to the app.

```php
use BoldlyGrow\Okta\ApiClient;

// Requests only okta.users.read for this call
$users = ApiClient::get(uri: 'users', scope: 'okta.users.read')->data;

// Requests only okta.groups.read for this call
$groups = ApiClient::get(uri: 'groups', scope: 'okta.groups.read')->data;
```

Pass more than one scope as a space-delimited string when a single call needs multiple:

```php
$response = ApiClient::get(
    uri: 'users/' . $id . '/appLinks',
    scope: 'okta.users.read okta.apps.read'
)->data;
```

If the `scope` argument is omitted while OAuth credentials are configured, the client throws a `ScopeException`. The legacy SSWS token path ignores the `scope` argument entirely.

Each token is cached for its full lifetime per unique scope set, so requesting scopes per call mints a few more tokens per hour but does not shorten caching (see [Token Caching](#token-caching)).

### Demonstrating Proof of Possession

On the **General** tab of the service app, the **Require Demonstrating Proof of Possession (DPoP) header in token requests** setting binds each access token to a key pair held by the client ([RFC 9449](https://datatracker.ietf.org/doc/html/rfc9449)). A leaked DPoP-bound access token cannot be replayed, because every request must also carry a single-use proof signed by the same key for that exact HTTP method and URL. We recommend leaving it enabled.

The client uses DPoP by default. If the setting isn't enabled on the service app, set `OKTA_API_DPOP=false` (or `'dpop' => false` in a [connection array](#connection-arrays)). If the app requires DPoP and the client has it disabled, the token request fails with an `UnauthorizedException` that explains the setting is required.

```php
OKTA_API_DPOP=false
```

No additional key management is needed. When DPoP is enabled, the client handles the protocol for you:

- **DPoP key:** Each time the client mints an access token, it generates a separate 2048-bit RSA key pair for DPoP. This key isn't your signing key, and it isn't registered in Okta. It's encrypted and cached with the access token for the token's lifetime.
- **Token request:** The token request carries a DPoP proof signed with the DPoP key. Okta replies with a `use_dpop_nonce` challenge, and the client retries with the server-provided nonce and a newly signed client assertion. The access token that Okta returns only works with proofs signed by that DPoP key.
- **API requests:** Each API request is sent with `Authorization: DPoP {token}` instead of `Bearer`, and a new `DPoP` proof header. The proof contains the HTTP method (`htm`), the URL without its query string and with its path percent-decoded (`htu`), a hash of the access token (`ath`), and a unique `jti`. If the API sends a `use_dpop_nonce` challenge, the request is retried once with the nonce.

If Okta rejects a DPoP proof, the exception message includes the reason that Okta returns in the `WWW-Authenticate` response header, for example `invalid_dpop_proof 'htu' claim in the DPoP proof JWT is invalid.`

### Token Caching

The client caches each access token so that it doesn't request a new token for every API call. Tokens are stored with the Laravel `Cache` facade in your application's default cache store.

- **Encryption:** The client never writes a plain access token to the cache. Before caching, it encrypts the access token, its token type, and the DPoP private key with Laravel's `encrypt()` helper, which uses your `APP_KEY` and the cipher in `config/app.php` (`AES-256-CBC` by default). Anyone who can read your cache store, such as Redis, the `cache` database table, or the cache files, sees only the encrypted value, which is useless without your `APP_KEY`.
- **Cache key:** `okta-api-token-` followed by an MD5 hash of the URL, client ID, scope string, key ID, private key reference (the file path, or a fingerprint of an inline key), and DPoP setting. There is one token for each combination, so changing any of these mints a new token on the next request.
- **Lifetime:** 59 minutes by default. Okta access tokens expire after 60 minutes, so this leaves a one-minute margin.
- **Rejected tokens:** If Okta rejects a cached token with a `401` response (for example, because it was revoked or the app's granted scopes changed), the request still fails. However, the token is removed from the cache, so the next request mints a new one. A `use_dpop_nonce` challenge does not remove the token.

To cache tokens for a shorter time, set `OKTA_API_TOKEN_CACHE_MINUTES` (or `token_cache_minutes` in the published config) to a whole number from `1` to `59`. Any other value throws a `ConfigurationException`, since a token cached for longer than 59 minutes could expire before it's removed from the cache. A shorter lifetime means that changes to the service app take effect sooner, but the client requests tokens more often.

```php
OKTA_API_TOKEN_CACHE_MINUTES=30
```

Which cache store you use determines how widely a token is shared:

| Cache store | Token is reused by |
|-------------|--------------------|
| `array` | The current process only, such as one Artisan command, one web request, or one queue worker until it restarts |
| `redis`, `database`, `file`, or another persistent store | Every process and server that shares the store |

To remove a cached token yourself, call `ApiToken::forget()` with the scope string, and the same connection array if the request uses one. Running `php artisan cache:clear` also removes it, along with everything else in the store.

```php
use BoldlyGrow\Okta\ApiToken;

ApiToken::forget(scope: 'okta.users.read');
```

If you rotate `APP_KEY`, add the old key to `APP_PREVIOUS_KEYS` or clear the cache. Otherwise, tokens that were cached before the rotation can't be decrypted.

### Key Rotation

Service apps support more than one registered public key, so you can rotate a key without downtime:

1. Generate a new key pair with a new `kid` (see [Key Generation Options](#key-generation-options)).
2. Add the new public key to the service app. Both keys are now active.
3. Update the private key that you supply (the secret in your secrets manager, the file at `private_key_path`, or `OKTA_API_PRIVATE_KEY`), and set `OKTA_API_KEY_ID` to the new `kid`. Restart or redeploy the application so that it loads the new values, and run `php artisan config:cache` again if you cache your config.
4. After you confirm that requests work, deactivate and delete the old key in the app's **Public Keys** table.

The token cache key includes the `kid`, so the first request after you change `OKTA_API_KEY_ID` mints a token with the new key.

To rotate an SSWS token, create a new token, update `OKTA_API_TOKEN`, and then revoke the old token on the **Tokens** tab of **Security > API**.

### Moving from SSWS to OAuth 2.0

You can move an existing integration from an SSWS token to OAuth 2.0 without changing how you call the client, except for adding scopes.

1. Create the service app, register a key, and grant scopes and an admin role (see [Quick Start: OAuth 2.0](#quick-start-oauth-20)).
2. Add the `scope` argument to every `ApiClient` call. When OAuth is configured, a call without a scope throws a `ScopeException`.
3. Set `OKTA_API_CLIENT_ID`, `OKTA_API_KEY_ID`, the private key variables, and `OKTA_API_DPOP=false` if the app doesn't use DPoP. When `OKTA_API_CLIENT_ID` is set, the client uses OAuth 2.0 and ignores `OKTA_API_TOKEN`.
4. After you confirm that requests work, remove `OKTA_API_TOKEN` and revoke the token in Okta.

### Why Client Secrets Aren't Supported

Okta service apps can also authenticate with a client ID and client secret, but this package doesn't support it. A client secret is only useful for a limited number of endpoints, which don't include the management API endpoints for users, groups, and apps that this package is built for. Okta's org authorization server rejects a client secret for tokens with management API scopes:

```plain
invalid_client: Client Credentials requests to the Org Authorization Server must use the private_key_jwt token_endpoint_auth_method.
```

A client secret only works against a custom authorization server for custom scopes, which can't read Okta resources. So the client authenticates with an RSA key pair instead. It signs a JWT with the private key (`private_key_jwt`), and the private key never leaves your application.

## Log Examples

This package uses the [boldlygrow/laravel-audit-log](https://github.com/boldlygrow/laravel-audit-log) package for standardized logs.

### Event Types

The `event_type` key should be used for any categorization and log searches.

- **Format:** `okta.api.{method}.{result/log_level}.{reason?}`
- **Methods:** `get|post|patch|put|delete`

| Status Code | Event Type                                      | Log Level |
|-------------|-------------------------------------------------|-----------|
| N/A         | `okta.api.test.success`                         | DEBUG     |
| N/A         | `okta.api.test.error.{okta_error_code}`         | CRITICAL  |
| N/A         | `okta.api.test.error.unknown`                   | CRITICAL  |
| N/A         | `okta.api.validate.error`                       | CRITICAL  |
| N/A         | `okta.api.get.process.pagination.started`       | DEBUG     |
| N/A         | `okta.api.get.process.pagination.finished`      | DEBUG     |
| N/A         | `okta.api.rate-limit.approaching`               | CRITICAL  |
| N/A         | `okta.api.rate-limit.exceeded` (Pre-Exception)  | CRITICAL  |
| N/A         | `okta.api.{method}.error.http.exception`        | ERROR     |
| 200         | `okta.api.{method}.success`                     | DEBUG     |
| 201         | `okta.api.{method}.success`                     | DEBUG     |
| 202         | `okta.api.{method}.success`                     | DEBUG     |
| 204         | `okta.api.{method}.success`                     | DEBUG     |
| 400         | `okta.api.{method}.warning.bad-request`         | WARNING   |
| 401         | `okta.api.{method}.error.unauthorized`          | ERROR     |
| 403         | `okta.api.{method}.error.forbidden`             | ERROR     |
| 404         | `okta.api.{method}.warning.not-found`           | WARNING   |
| 405         | `okta.api.{method}.error.method-not-allowed`    | ERROR     |
| 409         | `okta.api.{method}.error.conflict`              | ERROR     |
| 412         | `okta.api.{method}.error.precondition-failed`   | ERROR     |
| 422         | `okta.api.{method}.error.unprocessable`         | ERROR     |
| 429         | `okta.api.{method}.critical.rate-limit`         | CRITICAL  |
| 500         | `okta.api.{method}.critical.server-error`       | CRITICAL  |
| 501         | `okta.api.{method}.error.not-implemented`       | ERROR     |
| 503         | `okta.api.{method}.critical.server-unavailable` | CRITICAL  |
| Other 2xx   | `okta.api.{method}.success`                     | DEBUG     |
| Other 4xx   | `okta.api.{method}.error.client-error`          | ERROR     |
| Other 5xx   | `okta.api.{method}.critical.server-error`       | CRITICAL  |
| Other       | `okta.api.{method}.warning.unexpected-status`   | WARNING   |

### Successful Requests

#### GET Request Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Success {"event_type":"okta.api.get.success","method":"BoldlyGrow\\Okta\\ApiClient::get","event_ms":453,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"499","url":"https://dev-12345678.okta.com/api/v1/org"}}
```

#### GET Paginated Request Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Success {"event_type":"okta.api.get.success","method":"BoldlyGrow\\Okta\\ApiClient::get","count_records":200,"event_ms":1081,"event_ms_per_record":5,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"299","url":"https://dev-12345678.okta.com/api/v1/users?limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Paginated Results Process Started {"event_type":"okta.api.get.process.pagination.started","method":"BoldlyGrow\\Okta\\ApiClient::get","metadata":{"okta_request_id":"REDACTED","uri":"users"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":2346,"event_ms_per_record":11,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"298","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":1577,"event_ms_per_record":7,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"297","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":1115,"event_ms_per_record":5,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"296","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":1108,"event_ms_per_record":5,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"295","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":1067,"event_ms_per_record":5,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"294","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":1295,"event_ms_per_record":6,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"293","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":994,"event_ms_per_record":4,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"292","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":949,"event_ms_per_record":4,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"291","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":820,"event_ms_per_record":4,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"290","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":1060,"event_ms_per_record":5,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"289","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":200,"event_ms":741,"event_ms_per_record":3,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"288","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"okta.api.getPaginatedResults.success","method":"BoldlyGrow\\Okta\\ApiClient::getPaginatedResults","count_records":90,"event_ms":407,"event_ms_per_record":4,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"287","url":"https://dev-12345678.okta.com/api/v1/users?after=00uREDACTED&limit=200&search=status+eq+%22ACTIVE%22+or+%28status+eq+%22DEPROVISIONED%22+and+statusChanged+ge+%222023-10-01T15%3A02%3A15.491037Z%22%29"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Paginated Results Process Complete {"event_type":"okta.api.get.process.pagination.finished","method":"BoldlyGrow\\Okta\\ApiClient::get","count_records":2490,"duration_ms":14573,"duration_ms_per_record":5,"metadata":{"okta_request_id":"REDACTED","uri":"users"}}
```

#### POST Request Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::post Success {"event_type":"okta.api.post.success","method":"BoldlyGrow\\Okta\\ApiClient::post","event_ms":349,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"49","url":"https://dev-12345678.okta.com/api/v1/groups"}}
```

#### PATCH Request Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::patch Success {"event_type":"okta.api.patch.success","method":"BoldlyGrow\\Okta\\ApiClient::patch","event_ms":522,"metadata":{"okta_request_id":"0235f183fa446f5a2ae369ebfa8e8c5f","rate_limit_remaining":"49","url":"https://dev-12345678.okta.com/api/v1/users/00u1b2c3d4e5f6g7h8i9"}}
```

If the endpoint does not support partial updates with POST requests with the PATCH overlay method, use a PUT request instead.

```plain
[YYYY-MM-DD HH:II:SS] local.ERROR: ApiClient::patch Client Error {"event_type":"okta.api.patch.error.method-not-allowed","method":"BoldlyGrow\\Okta\\ApiClient::patch","errors":{"error_code":"E0000022","error_message":"The endpoint does not support the provided HTTP method","status_code":405},"event_ms":225,"metadata":{"okta_request_id":null,"rate_limit_remaining":null,"url":"https://dev-12345678.okta.com/api/v1/groups/00g1b2c3d4e5f6g7h8i9"}}
```

#### PUT Success Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::put Success {"event_type":"okta.api.put.success","method":"BoldlyGrow\\Okta\\ApiClient::put","event_ms":287,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"49","url":"https://dev-12345678.okta.com/api/v1/groups/00g1b2c3d4e5f6g7h8i9"}}
```

#### DELETE Success Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::delete Success {"event_type":"okta.api.delete.success","method":"BoldlyGrow\\Okta\\ApiClient::delete","event_ms":577,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"49","url":"https://dev-12345678.okta.com/api/v1/groups/00g1b2c3d4e5f6g7h8i9"}}
```

### Errors

#### 400 Bad Request

```plain
[YYYY-MM-DD HH:II:SS] local.WARNING: ApiClient::post Client Error {"event_type":"okta.api.post.warning.bad-request","method":"BoldlyGrow\\Okta\\ApiClient::post","errors":{"error_code":"E0000003","error_message":"The request body was not well-formed.","status_code":400},"event_ms":128,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"49","url":"https://dev-12345678.okta.com/api/v1/groups"}}
```

#### 401 Unauthorized

##### Environment Variables Not Set

```plain
[YYYY-MM-DD HH:II:SS] local.CRITICAL: ApiClient::validateConnection Error {"event_type":"okta.api.validate.error","method":"BoldlyGrow\\Okta\\ApiClient::validateConnection","errors":["The url field is required.","The token field is required."]}
```

##### Invalid Token

```plain
[YYYY-MM-DD HH:II:SS] local.ERROR: ApiClient::get Client Error {"event_type":"okta.api.get.error.unauthorized","method":"BoldlyGrow\\Okta\\ApiClient::get","errors":{"error_code":"E0000011","error_message":"Invalid token provided","status_code":401},"event_ms":261,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":null,"url":"https://dev-12345678.okta.com/api/v1/org"}}
```

#### 404 Not Found

```plain
[YYYY-MM-DD HH:II:SS] local.WARNING: ApiClient::get Client Error {"event_type":"okta.api.get.warning.not-found","method":"BoldlyGrow\\Okta\\ApiClient::get","errors":{"error_code":"E0000007","error_message":"Not found: Resource not found: 00u1b2c3d4e5f6g7h8i9 (User)","status_code":404},"event_ms":614,"metadata":{"okta_request_id":"REDACTED","rate_limit_remaining":"49","url":"https://dev-12345678.okta.com/api/v1/users/00u1b2c3d4e5f6g7h8i9"}}
```
