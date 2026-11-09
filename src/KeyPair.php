<?php

/**
 * @copyright Jefferson Martin
 * @license MIT <https://spdx.org/licenses/MIT.html>
 *
 * @link https://github.com/boldlygrow/okta-api-client
 */

namespace BoldlyGrow\Okta;

use BoldlyGrow\Okta\Exceptions\ConfigurationException;

/**
 * Generate an RSA key pair for an Okta service app.
 *
 * @author Jeff Martin
 *
 * The private key is returned as a PEM string for your application to store, and the public key is returned as a JWK
 * to register on the Okta service app.
 *
 * Usage:
 * ```php
 * use BoldlyGrow\Okta\KeyPair;
 *
 * $key_pair = KeyPair::generate(kid: 'acme-portal-production-2026-10');
 *
 * $key_pair['private_key']; // Store this in your secrets manager
 * $key_pair['public_jwk'];  // Register this in Okta
 * ```
 */
class KeyPair
{
    public const MINIMUM_BITS = 2048;

    /**
     * Generate an RSA key pair.
     *
     * @param  string|null  $kid
     *                             Optional key id. Defaults to the RFC 7638 JWK thumbprint.
     * @param  int          $bits
     *                             RSA key size in bits. A value below the minimum is raised to the minimum.
     *
     * @return array{private_key:string,public_jwk:array{kty:string,n:string,e:string,use:string,alg:string,kid:string}}
     *
     * @throws ConfigurationException
     */
    public static function generate(?string $kid = null, int $bits = self::MINIMUM_BITS): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => max(self::MINIMUM_BITS, $bits),
        ]);

        if ($key === false) {
            throw new ConfigurationException(implode(' ', [
                'Okta key pair error.',
                '(Reason) The RSA key pair could not be generated.',
                '(Solution) Check that the openssl extension is available.',
            ]));
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! openssl_pkey_export($key, $private_key)) {
            throw new ConfigurationException(implode(' ', [
                'Okta key pair error.',
                '(Reason) The generated RSA key pair could not be exported.',
            ]));
        }

        return [
            'private_key' => $private_key,
            'public_jwk' => PublicKeyJwk::fromPem($details['key'], $kid),
        ];
    }
}
