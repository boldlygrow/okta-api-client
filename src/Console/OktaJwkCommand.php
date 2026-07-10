<?php

/**
 * @copyright Jefferson Martin
 * @license MIT <https://spdx.org/licenses/MIT.html>
 * @link https://github.com/boldlygrow/okta-api-client
 */

namespace BoldlyGrow\Okta\Console;

use BoldlyGrow\Okta\PublicKeyJwk;
use Illuminate\Console\Command;

/**
 * Generate an RSA key pair, or convert an RSA public key to a JWK, for an Okta service app.
 *
 * @author Jeff Martin
 *
 * Examples:
 * ```plain
 * # Generate a new key pair, write the private key, and print the public JWK to register in Okta
 * php artisan okta:jwk --generate --out=okta-private-key.pem
 *
 * # Convert an existing RSA public key PEM to a JWK
 * php artisan okta:jwk okta-public-key.pem
 * ```
 */
class OktaJwkCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'okta:jwk
        {path? : Path to an RSA public key in PEM format}
        {--generate : Generate a new RSA key pair instead of reading an existing PEM file}
        {--out=okta-private-key.pem : File to write the private key to when using --generate}
        {--bits=2048 : RSA key size in bits when using --generate}
        {--kid= : Override the key id (defaults to the RFC 7638 JWK thumbprint)}';

    /**
     * @var string
     */
    protected $description = 'Convert an RSA public key to a JWK for an Okta service app, or generate a new key pair.';

    public function handle(): int
    {
        $kid = $this->option('kid') ?: null;

        if ($this->option('generate')) {
            return $this->generateAndPrint($kid);
        }

        $path = $this->argument('path');

        if (empty($path)) {
            $this->error('Provide a path to an RSA public key PEM, or pass --generate to create a new key pair.');

            return self::INVALID;
        }

        $this->line(self::toJson(PublicKeyJwk::fromPemFile($path, $kid)));

        return self::SUCCESS;
    }

    /**
     * Generate an RSA key pair, write the private key, and print the public JWK.
     *
     * @param string|null $kid
     */
    private function generateAndPrint(?string $kid): int
    {
        $bits = max(2048, (int) $this->option('bits'));

        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => $bits,
        ]);

        if ($resource === false) {
            $this->error('Failed to generate an RSA key pair. Check that the openssl extension is available.');

            return self::FAILURE;
        }

        openssl_pkey_export($resource, $private_pem);
        $public_pem = openssl_pkey_get_details($resource)['key'];

        $out = (string) $this->option('out');

        if (file_put_contents($out, $private_pem) === false) {
            $this->error('Could not write the private key to (' . $out . ').');

            return self::FAILURE;
        }

        @chmod($out, 0600);

        $this->info('Private key written to ' . $out . ' (' . $bits . '-bit RSA). Keep this file secret.');
        $this->warn('Store it in Google Secret Manager (production) or ~/.config/provisionr/dev (development).');
        $this->newLine();
        $this->line('Register this public JWK in your Okta service app (Add key):');
        $this->newLine();
        $this->line(self::toJson(PublicKeyJwk::fromPem($public_pem, $kid)));

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $jwk
     */
    private static function toJson(array $jwk): string
    {
        return (string) json_encode($jwk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
