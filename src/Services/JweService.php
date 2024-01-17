<?php
namespace Deriszadeh\Moadian\Services;

use phpseclib3\Crypt\RSA;

class JweService{

    public static function create(array $header, $publicKey, $payload){

        if( ! (isset($header['alg']) && $header['alg'] == 'RSA-OAEP-256')){
            throw new \Exception('Cannot create JWE, the supported "alg" is (RSA-OAEP-256).');
        }

        if( ! (isset($header['enc']) && $header['enc'] == 'A256GCM')){
            throw new \Exception('Cannot create JWE, the supported "enc" is (A256GCM).');
        }

        $cek = random_bytes(32);

        $rsa = RSA::loadPublicKey($publicKey);
        $encryptedKey = $rsa->encrypt($cek);

        $iv = openssl_random_pseudo_bytes(12);

        $AAD = Base64UrlEncoderService::encode(JsonService::encode($header));

        $encryptedData = openssl_encrypt($payload, 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag,  $AAD, 16);


        $jwe = Base64UrlEncoderService::encode(JsonService::encode($header)) . '.' . Base64UrlEncoderService::encode($encryptedKey) . '.' . Base64UrlEncoderService::encode($iv) . '.' . Base64UrlEncoderService::encode($encryptedData) . '.' . Base64UrlEncoderService::encode($tag);


        return $jwe;
    }

}