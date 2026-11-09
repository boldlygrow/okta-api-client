<?php

/**
 * @copyright Jefferson Martin
 * @license MIT <https://spdx.org/licenses/MIT.html>
 *
 * @link https://github.com/boldlygrow/okta-api-client
 */

namespace BoldlyGrow\Okta\Console;

use BoldlyGrow\Okta\Exceptions\ConfigurationException;
use BoldlyGrow\Okta\KeyPair;
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
 * php artisan okta-api-client:jwk --generate --out=okta-private-key.pem
 *
 * # Convert an existing RSA public key PEM to a JWK
 * php artisan okta-api-client:jwk okta-public-key.pem
 * ```
 */
class OktaJwkCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'okta-api-client:jwk
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
        $kid = $this->option('kid');
        $kid = is_string($kid) && $kid !== '' ? $kid : null;

        if ($this->option('generate')) {
            return $this->generateAndPrint($kid);
        }

        $path = $this->argument('path');

        if (! is_string($path) || $path === '') {
            $this->error('Provide a path to an RSA public key PEM, or pass --generate to create a new key pair.');

            return self::INVALID;
        }

        $this->line(self::toJson(PublicKeyJwk::fromPemFile($path, $kid)));

        return self::SUCCESS;
    }

    /**
     * Generate an RSA key pair, write the private key, and print the public JWK.
     *
     * @param  string|null  $kid  Public Key ID
     */
    private function generateAndPrint(?string $kid): int
    {
        $bits = max(KeyPair::MINIMUM_BITS, (int) $this->option('bits'));

        try {
            $key_pair = KeyPair::generate($kid, $bits);
        } catch (ConfigurationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $out = $this->option('out');
        $out = is_string($out) ? $out : '';

        if (file_put_contents($out, $key_pair['private_key']) === false) {
            $this->error('Could not write the private key to (' . $out . ').');

            return self::FAILURE;
        }

        @chmod($out, 0600);

        $this->info('Private key written to ' . $out . ' (' . $bits . '-bit RSA). Keep this file secret.');
        $this->warn('In production, store it in a secrets manager and load it at runtime (see Private Key Storage in the README).');
        $this->newLine();
        $this->line('Register this public JWK in your Okta service app (Add key):');
        $this->newLine();
        $this->line(self::toJson($key_pair['public_jwk']));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $jwk
     */
    private static function toJson(array $jwk): string
    {
        return (string) json_encode($jwk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
