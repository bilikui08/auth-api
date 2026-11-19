<?php

namespace Tests\Concerns;

use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Passport REAL en los tests: claves RSA de prueba y un cliente OAuth con los grants que usa auth-api, para emitir y
 * decodificar tokens de verdad (login, refresh, empresa activa). Usar setUpPassport() / tearDownPassport().
 */
trait UsesRealPassport
{
    private string $keyDir;

    protected function setUpPassport(): void
    {
        $this->keyDir = sys_get_temp_dir() . '/auth-api-test-keys-' . getmypid();
        @mkdir($this->keyDir);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        file_put_contents($this->keyDir . '/oauth-private.key', $private);
        file_put_contents($this->keyDir . '/oauth-public.key', openssl_pkey_get_details($key)['key']);
        chmod($this->keyDir . '/oauth-private.key', 0600);
        chmod($this->keyDir . '/oauth-public.key', 0600);
        Passport::loadKeysFrom($this->keyDir);

        $client = app(ClientRepository::class)->createPasswordGrantClient('tests', 'users', true);
        $client->forceFill(['grant_types' => ['password', 'refresh_token', 'organization_selection']])->save();
        config([
            'services.passport.password_client_id' => $client->getKey(),
            'services.passport.password_client_secret' => $client->plainSecret,
        ]);
    }

    protected function tearDownPassport(): void
    {
        @unlink($this->keyDir . '/oauth-private.key');
        @unlink($this->keyDir . '/oauth-public.key');
        @rmdir($this->keyDir);
        Passport::loadKeysFrom(storage_path()); // la ruta de claves es estática: no puede quedar apuntando a la carpeta borrada
    }
}
