<?php

/**
 * @copyright Jefferson Martin
 * @license MIT <https://spdx.org/licenses/MIT.html>
 * @link https://github.com/boldlygrow/okta-api-client
 */

namespace BoldlyGrow\Okta;

use BoldlyGrow\Okta\Exceptions\ConfigurationException;

/**
 * Convert an RSA public key to a JWK for registration on an Okta service app.
 *
 * @author Jeff Martin
 *
 * Okta's "Add key" dialog for Public key/Private key client authentication accepts a public key in JSON Web Key
 * (JWK) format, not PEM. This action converts an RSA public key (PEM / SPKI) into a complete JWK, including a stable
 * `kid` derived from the RFC 7638 JWK thumbprint, using only the openssl extension this package already requires.
 *
 * Usage:
 * ```php
 * use BoldlyGrow\Okta\PublicKeyJwk;
 *
 * // Static
 * $jwk = PublicKeyJwk::fromPemFile('okta-public-key.pem');
 * $jwk = PublicKeyJwk::fromPem($pemString);
 *
 * // Invokable action
 * $jwk = (new PublicKeyJwk)($pemString);
 * $jwk = app(PublicKeyJwk::class)($pemString);
 * ```
 */
class PublicKeyJwk
{
    /**
     * Convert an RSA public key PEM file into a JWK array.
     *
     * @param string $path
     *      Path to an RSA public key in PEM (SPKI) format.
     *
     * @param string|null $kid
     *      Optional key id. Defaults to the RFC 7638 JWK thumbprint.
     *
     * @return array{kty:string,n:string,e:string,use:string,alg:string,kid:string}
     *
     * @throws ConfigurationException
     */
    public static function fromPemFile(string $path, ?string $kid = null): array
    {
        if (!is_readable($path)) {
            throw new ConfigurationException(implode(' ', [
                'Okta public key error.',
                '(Reason) The public key file was not found or is not readable at (' . $path . ').'
            ]));
        }

        return self::fromPem((string) file_get_contents($path), $kid);
    }

    /**
     * Convert an RSA public key PEM string into a JWK array.
     *
     * @param string $pem
     *      An RSA public key in PEM (SPKI) format.
     *
     * @param string|null $kid
     *      Optional key id. Defaults to the RFC 7638 JWK thumbprint.
     *
     * @return array{kty:string,n:string,e:string,use:string,alg:string,kid:string}
     *
     * @throws ConfigurationException
     */
    public static function fromPem(string $pem, ?string $kid = null): array
    {
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new ConfigurationException(implode(' ', [
                'Okta public key error.',
                '(Reason) The provided PEM is not a valid public key.',
                '(Solution) Pass a public key in PEM/SPKI format (a "-----BEGIN PUBLIC KEY-----" block),',
                'for example the output of `openssl rsa -in private.pem -pubout`.'
            ]));
        }

        $details = openssl_pkey_get_details($key);

        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new ConfigurationException(implode(' ', [
                'Okta public key error.',
                '(Reason) Only RSA public keys are supported (RS256).'
            ]));
        }

        $n = self::base64UrlEncode($details['rsa']['n']);
        $e = self::base64UrlEncode($details['rsa']['e']);

        return [
            'kty' => 'RSA',
            'n' => $n,
            'e' => $e,
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid ?: self::thumbprint($n, $e),
        ];
    }

    /**
     * Allow the class to be used as an invokable action.
     *
     * @param string $pem
     * @param string|null $kid
     *
     * @return array{kty:string,n:string,e:string,use:string,alg:string,kid:string}
     *
     * @throws ConfigurationException
     */
    public function __invoke(string $pem, ?string $kid = null): array
    {
        return self::fromPem($pem, $kid);
    }

    /**
     * Compute the RFC 7638 JWK thumbprint (SHA-256, base64url) for an RSA key.
     *
     * The thumbprint is computed over the required members only, in lexicographic order (`e`, `kty`, `n`), with no
     * whitespace. It provides a stable, deterministic key id.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc7638
     *
     * @param string $n
     * @param string $e
     */
    private static function thumbprint(string $n, string $e): string
    {
        return self::base64UrlEncode(hash('sha256', (string) json_encode([
            'e' => $e,
            'kty' => 'RSA',
            'n' => $n,
        ]), true));
    }

    /**
     * Base64url encode without padding, per the JWS/JWK spec.
     *
     * @param string $bytes
     */
    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
