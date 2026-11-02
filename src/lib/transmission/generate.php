<?php

function generateKeyPair($publicKeyPath, $privateKeyPath): void
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);
    $privateKey = sodium_crypto_sign_secretkey($keypair);

    if (
        file_put_contents($publicKeyPath, $publicKey) === false ||
        file_put_contents($privateKeyPath, $privateKey) === false
    ) {
        throw new RuntimeException('Could not write key pair');
    }

    if (!chmod($publicKeyPath, 0644) || !chmod($privateKeyPath, 0600)) {
        throw new RuntimeException('Could not set key file permissions');
    }
}   
