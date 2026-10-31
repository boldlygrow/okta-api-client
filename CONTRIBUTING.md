# Contributing Guide

This project does not have formalized or rigid contribution processes. We keep it simple and subscribe to a "see something, say something" philosophy with a "if it's broken, figure out where and fix it". Due to the simple architecture, it's likely that any problems encountered can be fixed within a single method or with a find and replace of a repeated line of code.

Please consider these to be guidelines. If in doubt, please create an issue and tag the [maintainers](README.md#maintainers) to discuss.

## Feature Requests and Ideas

> **Disclaimer:** This is not an official package maintained by any company. Please use at your own risk and create merge requests for any bugs that you encounter.

We do not maintain a roadmap of feature requests, however we invite you to contribute and we will gladly review your merge requests.

## Code Contributions

We have transitioned from issue-first to PR-first development. We will create an issue for any deferred work, however you can start contributing by creating a new `feature/*` or `hotfix/*` branch and create a merge request.

Before assigning your PR to a maintainer, please review the pipeline CI job outputs for any errors and fix anything that appears.

All merge requests can be assigned to one or all of the maintainers at your discretion. It is helpful to add a comment with any context that the maintainer/reviewer should know or be on the look out for.

### Changelog

Release notes live in the root `CHANGELOG.md`, newest release first. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) with two project-specific additions.

- Each release is an `h2` heading of the form `## [5.0.0] - 2026-10-31`, with a matching link reference at the bottom of the file. Because some historical git tags are two-component (`4.2`, `3.0`), those link references point at the real tag names even though the heading uses the full `X.Y.Z` label.
- Categories are `h3` and use Keep a Changelog's vocabulary — **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**, **Security** — plus **Overview** and **Upgrade Steps**, which are reserved for major releases. Omit any category with nothing to report; do not write "none this release".
- Deprecations and removals go in their own category. Do not bury them inside **Changed**, where nobody looking for them will find them.
- Every non-trivial entry carries a `**Why:**` sub-bullet explaining the reasoning, and a `**How:**` sub-bullet wherever a consumer has to take action. This rationale is the point of the changelog — an entry that only restates the diff is not worth writing.
- Start each entry that has details with a short bold headline that says what changed (ex. `**Exception messages include the reason that Okta returned**`), and put the details, `**Why:**`, and `**How:**` in sub-bullets underneath. Keep each sub-bullet to one or two sentences so the headlines can be skimmed. A one-line entry with no details does not need the bold.
- Large releases may group entries under `h4` thematic subheads inside a category (see 5.0's **Added**).
- Tag releases as `X.Y.Z` with no `v` prefix.

### Laravel Test Application

You can create a new Laravel application for a specific version to perform local testing with. This allows you to easily use Tinkerwell for each
respective Laravel version.

```bash
# Set temporary environment variable
export SDK_LARAVEL_VERSION=10
cd ~/Code
# Create new Laravel projects
composer create-project laravel/laravel:^${SDK_LARAVEL_VERSION}.0 laravel${SDK_LARAVEL_VERSION}-pkg-test
# Create symlinks in directory
mkdir -p laravel${SDK_LARAVEL_VERSION}-pkg-test/packages/provisionesta
ln -s ~/Code/okta-api-client ~/Code/laravel${SDK_LARAVEL_VERSION}-pkg-test/packages/provisionesta/okta-api-client
# Custom repository location configuration
cd ~/Code/laravel${SDK_LARAVEL_VERSION}-pkg-test
sed -i '.bak' -e 's/seeders\/"/&,\n            "BoldlyGrow\\\\Okta\\\\": "packages\/provisionesta\/okta-api-client\/src"/g' composer.json
composer config repositories.okta-api-client '{"type": "path", "url": "packages/provisionesta/okta-api-client"}' --file composer.json
composer require provisionesta/okta-api-client:dev-main
php artisan vendor:publish --tag=okta-api-client
# Unset temporary environment variable
unset SDK_LARAVEL_VERSION
```

You can link this package into an existing Laravel application using the following commands.

```bash
ce ~/Code/my-project-name

mkdir -p packages/provisionesta
ln -s ~/Code/okta-api-client packages/provisionesta/okta-api-client
sed -i '.bak' -e 's/seeders\/"/&,\n            "BoldlyGrow\\\\Okta\\\\": "packages\/provisionesta\/okta-api-client\/src"/g' composer.json
composer config repositories.okta-api-client '{"type": "path", "url": "packages/provisionesta/okta-api-client"}' --file composer.json
composer require provisionesta/okta-api-client:dev-main
php artisan vendor:publish --tag=okta-api-client
```

## Custom Application Configuration

### Configuring Your Application with Working Copies of Packages

When you run `composer install`, you will get the latest copy of the packages from the GitHub and GitLab repositories. However, you won't be able to see real-time changes if you change any code in the packages.

You can mitigate this problem by creating a local symlink (with resolved namespaces) for the package inside of your application that you're using for development and testing. By symlinking the packages into the newly created `packages` directory, you'll be able to preview and test your work without doing any Git commits (bad practice).

```bash
# Pre-Requisite (you should already have this)
# You can use any directory you want (if not using ~/Code)
cd ~/Code
git clone https://gitlab.com/provisionesta/okta-api-client.git
```

```bash
cd ~/Code/{my-laravel-app}
mkdir -p packages/provisionesta
cd packages/provisionesta
ln -s ~/Code/okta-api-client okta-api-client
```

### Application Composer

Update the `composer.json` file in your testing application (not the package) to add the package to the `autoload.psr-4` array (append the array, don't replace anything).

```json
# ~/Code/{my-laravel-app}/composer.json

"autoload": {
    "psr-4": {
        "App\\": "app/",
        "BoldlyGrow\\Okta\\": "packages/provisionesta/okta-api-client/src",
    }
},
```

### Configure Local Composer Repository

Credit: [https://laravel-news.com/developing-laravel-packages-with-local-composer-dependencies](https://laravel-news.com/developing-laravel-packages-with-local-composer-dependencies)

```bash
cd ~/Code/{my-laravel-app}

composer config repositories.okta-api-client '{"type": "path", "url": "packages/provisionesta/okta-api-client"}' --file composer.json

composer require provisionesta/okta-api-client:dev-main

# Package operations: 1 install, 0 updates, 0 removals
#  - Installing provisionesta/okta-api-client (dev-main): Symlinking from packages/provisionesta/okta-api-client
```

### Validation and Config Copy

```bash
php artisan vendor:publish --tag=okta-api-client

# Copied File [/Users/jmartin/Code/okta-api-client/src/Config/okta-api-client.php] To [/config/okta-api-client.php]
# Publishing complete.
```

### Caching Problems

If you run into any classes or files that are renamed and are throwing `Not Found` errors, you may need to use the `composer dump-autoload` command.
