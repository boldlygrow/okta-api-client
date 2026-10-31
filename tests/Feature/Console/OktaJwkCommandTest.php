<?php

it('generates an owner-only private key and prints the public JWK with the okta-api-client:jwk command', function () {
    $private_key_path = sys_get_temp_dir() . '/okta-api-client-jwk-test-' . uniqid() . '.pem';

    try {
        $this->artisan('okta-api-client:jwk', ['--generate' => true, '--out' => $private_key_path])
            ->expectsOutputToContain('"kty": "RSA"')
            ->assertSuccessful();

        expect(openssl_pkey_get_private((string) file_get_contents($private_key_path)))->not->toBeFalse();
        expect(fileperms($private_key_path) & 0777)->toBe(0600);
    } finally {
        @unlink($private_key_path);
    }
});
