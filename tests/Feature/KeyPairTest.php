<?php

use BoldlyGrow\Okta\KeyPair;
use BoldlyGrow\Okta\PublicKeyJwk;

it('generates a private key and the JWK of its public key', function () {
    $key_pair = KeyPair::generate();

    $private_key = openssl_pkey_get_private($key_pair['private_key']);
    $public_pem = openssl_pkey_get_details($private_key)['key'];

    expect($key_pair['private_key'])->toStartWith('-----BEGIN PRIVATE KEY-----');
    expect($key_pair['public_jwk'])->toBe(PublicKeyJwk::fromPem($public_pem));
});

it('uses the given key id', function () {
    expect(KeyPair::generate(kid: 'acme-2026-11')['public_jwk']['kid'])->toBe('acme-2026-11');
});

it('raises a key size below the minimum to the minimum', function () {
    $private_key = openssl_pkey_get_private(KeyPair::generate(bits: 1024)['private_key']);

    expect(openssl_pkey_get_details($private_key)['bits'])->toBe(KeyPair::MINIMUM_BITS);
});
