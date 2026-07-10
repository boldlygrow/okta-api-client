# Okta API Client

[[_TOC_]]

## Overview

The Okta API Client is an open source [Composer](https://getcomposer.org/) package for use in Laravel applications for connecting to Okta for provisioning and deprovisioning of users, groups, applications, and other related functionality.

Please use at your own risk and create merge requests for any bugs that you encounter.

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

We do not maintain a roadmap of feature requests, however we invite you to contribute and we will gladly review your merge requests.

Please create an [issue](https://github.com/boldlygrow/okta-api-client/issues) for bug reports.

### Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) to learn more about how to contribute.

### Maintainers

| Name | GitLab Handle | Email |
|------|---------------|-------|
| [Jeff Martin](https://www.linkedin.com/in/jeffersonmmartin/) | [@jeffersonmartin](https://github.com/jeffersonmartin) | `jeff [at] boldlygrow [dot] us` |

### Contributor Credit

- Dillon Wheeler
- Jeff Martin

## Installation

### Requirements

| Requirement | Version                          |
|-------------|----------------------------------|
| PHP         | `^8.0`                           |
| Laravel     | `^8.0`, `^9.0`, `^10.0`, `^11.0`, `^12.0`, `^13.0` |

OAuth 2.0 authentication requires no additional Composer packages; the JWT client assertion is signed with the `openssl` extension. This package does not integrate with any secrets manager, so there is no cloud SDK dependency. Fetching the signing key from a secrets manager, if you use one, is done in your own application code (see [Private Key Storage](#private-key-storage)).

### Upgrade Guide

See the [changelog](https://github.com/boldlygrow/okta-api-client/tree/main/changelog) for release notes.

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

| Method | Credential | Best for |
|--------|------------|----------|
| OAuth 2.0 (`private_key_jwt`) | Client ID + private key | New integrations, least privilege, automatic rotation |
| SSWS API token (legacy) | Static API token | Existing deployments, quick local testing |

### Environment Variables

Add the connection variables to your `.env` file. You can add these anywhere in the file on a new line.

For OAuth 2.0:

```php
OKTA_API_URL="https://mycompany.okta.com"
OKTA_API_CLIENT_ID="0oaExampleClientId"
OKTA_API_KEY_ID="the-registered-kid"

# Provide the signing key one of two ways (see Private Key Storage):
# a path to a PEM file (local development or a mounted secret) ...
OKTA_API_PRIVATE_KEY_PATH="/var/secrets/okta/private-key.pem"
# ... or an inline PEM string (often resolved from a secrets manager in code).
# OKTA_API_PRIVATE_KEY=
```

There is no scope environment variable. The scope is supplied per request as the `scope` argument (see [Scopes](#scopes)). For production, the private key is commonly resolved from a secrets manager and passed in the connection array rather than set in the environment (see [Private Key Storage](#private-key-storage)).

For the legacy SSWS token:

```php
OKTA_API_URL="https://mycompany.okta.com"
OKTA_API_TOKEN="S3cr3tK3yG03sH3r3"
```

If you have your connection secrets stored in your database or a secrets manager, you can override the `config/okta-api-client.php` configuration or provide a connection array on each request. See [connection arrays](#connection-arrays) to learn more.

#### URL

Each Okta customer is provided with a subdomain for their company. This is sometimes referred to as a tenant or `${yourOktaDomain}` in the API documentation. You can also use an Okta Preview instance.

If you're just getting started, it is recommended to use a free [Okta developer account](https://developer.okta.com/signup/).

```php
OKTA_API_URL="https://mycompany.okta.com"

OKTA_API_URL="https://mycompany.oktapreview.com"

OKTA_API_URL="https://dev-12345678.okta.com"
```

### OAuth 2.0 for Okta

Okta requires the `private_key_jwt` client authentication method for access tokens that carry management API scopes. Client ID and client secret is **not** supported for reading users, groups, or apps. A client secret only works against a custom authorization server for custom scopes, which cannot read Okta resources. This client therefore authenticates with a Client ID and a private key, never a secret.

#### Create the service app

1. In the Okta Admin Console, go to **Applications > Create App Integration**.
2. Select **API Services** as the sign-in method and click **Next**.
3. Enter an app name (for example "Provisionr integration") and click **Save**.

This creates the app in Client Credentials mode. The next sections register your signing key and grant the scopes and admin role. The scopes you grant are the hard ceiling on what any token can do; a narrower scope requested at runtime can never exceed the grant, so grant only what the integration needs.

#### Generate and register a signing key

OAuth for Okta uses an RSA public/private key pair instead of a shared secret. You keep the **private key** (this package signs a JWT with it on every token request), and you register the matching **public key** with the Okta app so Okta can verify that signature. RS256 is the default algorithm.

There are two ways to get the key pair. Choose one.

##### Option A: Let Okta generate the key pair (quickest, testing)

1. In your API Services app, open the **General** tab. In the **Client Credentials** section, click **Edit**.
2. For **Client authentication**, select **Public key / Private key**.
3. In the **Public Keys** section, click **Add key**, then **Generate new key**.
4. Okta shows the new key pair. Click **PEM** to view the private key in PEM format, then **Copy to clipboard**. This is the **only** time Okta shows the private key, so save it now. Okta does not store it.
5. Click **Done**, then **Save**.
6. In the **Public Keys** table, note the **Key ID (KID)** for the key you just added. You will set this as `OKTA_API_KEY_ID`.

Okta recommends this option for testing only, because Okta generated (and briefly held) the private key. For production, generate the key yourself so the private key never leaves your control. Use Option B.

##### Option B: Generate your own key pair (recommended for production)

The package ships an Artisan command that generates an RSA key pair, writes the private key, and prints the public JWK ready to paste into Okta. This is the simplest path and needs no OpenSSL CLI or extra tooling:

```plain
php artisan okta:jwk --generate --out=okta-private-key.pem
```

This writes `okta-private-key.pem` (the **private key**, `0600`) and prints the public JWK. Keep the private key secret: store it in your secrets manager and pass it in at runtime, or point `OKTA_API_PRIVATE_KEY_PATH` at the file. Never commit it. The printed JWK looks like this (your `n` and `kid` will differ):

```json
{
  "kty": "RSA",
  "n": "0vx7agoebGcQSuu…",
  "e": "AQAB",
  "use": "sig",
  "alg": "RS256",
  "kid": "NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs"
}
```

If you already have a public key PEM (for example one generated by your own key management process), convert it to a JWK instead of generating a new pair:

```plain
php artisan okta:jwk okta-public-key.pem
```

To generate the pair with OpenSSL directly and convert it, all three steps are equivalent:

```plain
openssl genrsa -out okta-private-key.pem 2048
openssl rsa -in okta-private-key.pem -pubout -out okta-public-key.pem
php artisan okta:jwk okta-public-key.pem
```

The conversion is also available programmatically as an action, so you can build the JWK inside your own provisioning or key-rotation code:

```php
use BoldlyGrow\Okta\PublicKeyJwk;

$jwk = PublicKeyJwk::fromPemFile('okta-public-key.pem');
// or
$jwk = PublicKeyJwk::fromPem($publicKeyPemString);
```

Then register the public key:

3. In your API Services app, open the **General** tab. In **Client Credentials**, click **Edit**, set **Client authentication** to **Public key / Private key**, then in **Public Keys** click **Add key**. Paste the entire JWK JSON, click **Done**, then **Save**.

4. In the **Public Keys** table, copy the **Key ID (KID)** that Okta shows for the key. Set `OKTA_API_KEY_ID` to that value.

#### Finish the app configuration

Regardless of which option you chose:

1. On the **Okta API Scopes** tab, click **Grant** for each scope your integration needs, for example `okta.users.read`, `okta.groups.read`, and `okta.apps.read`.
2. On the **Admin roles** tab, assign a least-privilege role such as `Read-only Administrator`. Do not assign `Super Administrator`.
3. On the **General** tab, copy the **Client ID** and set it as `OKTA_API_CLIENT_ID`.
4. Store the private key per the [Private Key Storage](#private-key-storage) section, and set `OKTA_API_KEY_ID` to the Key ID from the previous step.

The `kid` is included in the signed assertion header so Okta knows which registered public key to verify against. Setting `OKTA_API_KEY_ID` is required once the app has more than one registered key, and harmless to always set.

#### Scopes

The scope is provided per request as the `scope` argument. There is no scope default in config or the environment, so each OAuth call declares exactly the authority it needs.

```php
use BoldlyGrow\Okta\ApiClient;

// Requests only okta.users.read for this call
$users = ApiClient::get(uri: 'users', scope: 'okta.users.read')->data;

// Requests only okta.groups.read for this call
$groups = ApiClient::get(uri: 'groups', scope: 'okta.groups.read')->data;
```

Pass more than one scope as a space-delimited string when a single call needs multiple:

```php
$response = ApiClient::get(uri: 'users/' . $id . '/appLinks', scope: 'okta.users.read okta.apps.read')->data;
```

If the `scope` argument is omitted while OAuth credentials are configured, the client throws a `ScopeException`. The legacy SSWS token path ignores the `scope` argument entirely.

Each token is cached for its full lifetime (about 59 minutes) per unique scope set, so requesting scopes per call mints a few more tokens per hour but does not shorten caching.

#### Private Key Storage

The private key is provided in one of two ways. This package does not integrate with any secrets manager; fetching the key from a vault or secrets manager is your application's responsibility, which keeps the package independent of your storage choice and free of cloud SDK dependencies.

| Field | Value | Use |
|-------|-------|-----|
| `private_key` (`OKTA_API_PRIVATE_KEY`) | An inline PEM string | Production (resolved from your secrets manager at runtime), tests, CI |
| `private_key_path` (`OKTA_API_PRIVATE_KEY_PATH`) | A path to a PEM file (leading `~` expanded) | Local development, or a secret mounted as a file |

Provide one or the other. `private_key` takes precedence when both are set.

##### Resolve the key from a secrets manager (connection array)

Fetch the PEM from wherever you store it and pass it as `private_key` in a per-request connection array. This is the recommended pattern for multi-tenant applications, since each tenant can supply its own key. The example below uses Google Secret Manager, but any source works.

```php
use BoldlyGrow\Okta\ApiClient;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;

// Fetch the PEM in your own code. Swap this for Vault, AWS Secrets Manager,
// a mounted file, an HTTP call, etc. Cache it as appropriate for your app.
$client = new SecretManagerServiceClient();
$name = $client->secretVersionName('my-gcp-project', 'okta-mycompany-private-key', 'latest');
$privateKey = $client->accessSecretVersion(
    (new AccessSecretVersionRequest())->setName($name)
)->getPayload()->getData();

$connection = [
    'url' => 'https://mycompany.okta.com',
    'client_id' => '0oaExampleClientId',
    'key_id' => 'the-registered-kid',
    'private_key' => $privateKey,
];

$users = ApiClient::get(uri: 'users', scope: 'okta.users.read', connection: $connection)->data;
```

##### Resolve the key in the published config

If you use a single service app for the whole application, publish the config and set the key there instead of passing a connection array on every call. Either point the environment variable at a mounted file:

```php
OKTA_API_PRIVATE_KEY_PATH="/var/secrets/okta/private-key.pem"
```

Or resolve it dynamically in the published `config/okta-api-client.php`, since a config file is plain PHP:

```php
// config/okta-api-client.php (after php artisan vendor:publish --tag=okta-api-client)
'private_key' => app(\App\Support\OktaKeyResolver::class)->pem(),
```

Loading a secret in the config file runs on every request and during `config:cache`, so have your resolver cache the value (and be aware `config:cache` freezes whatever it returns at build time). Passing the key in the connection array is usually the cleaner choice when the value is dynamic or per-tenant.

### Legacy SSWS API Token

See the Okta documentation for [creating an API token](https://developer.okta.com/docs/guides/create-an-api-token/main/).

An API token uses the permissions of the user it belongs to, so create a dedicated service account (bot) user for production use cases. Assign the `Read-only Administrator` role and add custom permissions as needed. For safety, do not grant the `Super Administrator` role. Tokens that are inactive for 30 days without API calls automatically expire.

Set the `OKTA_API_TOKEN` in your `.env` file:

```php
OKTA_API_TOKEN="S3cr3tK3yG03sH3r3"
```

> **Internal Developer Note:** The API token is automatically prefixed with `SSWS ` when used by the API Client. It does not need to be included when defining the variable value.

### Connection Arrays

The variables that you define in your `.env` file are used by default unless you set the connection argument with an array. A connection array contains the `url` and either an OAuth `client_id` (with key location fields) or a legacy `token`.

> **Security Warning:** Do not commit a hard coded API token or private key into your code base. This should only be used with dynamic variables that are stored in your database or secrets manager.

OAuth 2.0 connection array:

```php
$connection = [
    'url' => 'https://mycompany.okta.com',
    'client_id' => '0oaExampleClientId',
    'key_id' => 'the-registered-kid',
    'private_key' => $privateKeyPem, // an inline PEM string (see Private Key Storage)
    // or, instead of private_key:
    // 'private_key_path' => '/var/secrets/okta/private-key.pem',
];
```

The scope is not part of the connection array. It is passed per request as the `scope` argument.

Legacy SSWS connection array:

```php
$connection = [
    'url' => 'https://mycompany.okta.com',
    'token' => 'S3cr3tK3yG03sH3r3',
];
```

Passing a connection array per request is the recommended pattern for multi-tenant applications, where each tenant has its own `client_id` and key. The OAuth token cache is keyed per connection and scope set, so tenants never share a token.

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

Grant the service app only the scopes it needs and assign a read-only admin role. The app's granted scopes are the real least-privilege boundary; requesting narrower scopes per call is defense in depth on top of that grant, not a replacement for it.

#### No Shared Credentials

Do not reuse an OAuth app or API token created for another purpose. Create a dedicated service app (or token) per use case so that revoking a compromised credential does not affect unrelated systems.

#### Credential Storage

Do not add your API token or private key to any `config/*.php` files that are committed to your repository (secret leak).

In production, store the private key in a secrets manager or vault and resolve it at runtime rather than committing it or baking it into an image. This package does not fetch the key for you; retrieve it in your own code and pass it as `private_key` in the connection array, or point `private_key_path` at a mounted secret file. For local development, keep the key file outside the repository. All `.env` values should remain in the `.gitignore`-excluded `.env` file.

#### Rotation

OAuth service apps support multiple registered public keys, so you can add a new key, start signing with its `kid`, and retire the old key with no downtime. To roll a key, register the new public key in Okta, then update the private key you supply (the connection array value, the file at `private_key_path`, or `OKTA_API_PRIVATE_KEY`) and set `OKTA_API_KEY_ID` to the new `kid`. Because the token cache key includes the `kid` and a fingerprint of the inline key, changing either takes effect immediately; a rotated file at the same `private_key_path` is picked up within the token cache lifetime (at most about 59 minutes).

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

| Code | Exception Class                                             |
|------|-------------------------------------------------------------|
| 400  | `BoldlyGrow\Okta\Exceptions\BadRequestException`         |
| 401  | `BoldlyGrow\Okta\Exceptions\UnauthorizedException`       |
| 403  | `BoldlyGrow\Okta\Exceptions\ForbiddenException`          |
| 404  | `BoldlyGrow\Okta\Exceptions\NotFoundException`           |
| 412  | `BoldlyGrow\Okta\Exceptions\PreconditionFailedException` |
| 422  | `BoldlyGrow\Okta\Exceptions\UnprocessableException`      |
| 429  | `BoldlyGrow\Okta\Exceptions\RateLimitException`          |
| 500  | `BoldlyGrow\Okta\Exceptions\ServerErrorException`        |

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
| 412         | `okta.api.{method}.error.precondition-failed`   | DEBUG     |
| 422         | `okta.api.{method}.error.unprocessable`         | DEBUG     |
| 429         | `okta.api.{method}.critical.rate-limit`         | CRITICAL  |
| 500         | `okta.api.{method}.critical.server-error`       | CRITICAL  |
| 501         | `okta.api.{method}.error.not-implemented`       | ERROR     |
| 503         | `okta.api.{method}.critical.server-unavailable` | CRITICAL  |

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
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Paginated Results Process Complete {"event_type":"okta.api.get.process.pagination.finished","method":"BoldlyGrow\\Okta\\ApiClient::get","duration_ms":14573,"metadata":{"okta_request_id":"REDACTED","uri":"users"}}
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
