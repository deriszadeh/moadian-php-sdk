<?php
namespace Deriszadeh\Moadian\Services;

use phpseclib3\Crypt\RSA;

class JwsService{

    public static function create($privateKey, array $header, array $payload){

        if( ! (isset($header['alg']) && $header['alg'] == 'RS256')){
            throw new \Exception('Cannot create JWS, the supported "alg" is (RS256).');
        }

        $jwtHeader = Base64UrlEncoderService::encode(JsonService::encode($header));

        $jwtPayload = Base64UrlEncoderService::encode(JsonService::encode($payload));

        $signAlg = '';

        if($header['alg'] == 'RS256'){
            $signAlg = 'sha256WithRSAEncryption';
        }

        openssl_sign( $jwtHeader.".".$jwtPayload, $jwtSig,  $privateKey,  $signAlg );

        $jwtSig = Base64UrlEncoderService::encode($jwtSig);

        $jws = $jwtHeader.".".$jwtPayload.".".$jwtSig;

        return $jws;
    }

}