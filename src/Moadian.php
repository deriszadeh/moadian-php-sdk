<?php

namespace Deriszadeh\Moadian;

use Deriszadeh\Moadian\Exceptions\CommunicateException;
use phpseclib3\Crypt\RSA;

class Moadian
{

    public $apiBaseUrl = 'https://tp.tax.gov.ir/requestsmanager/api/v2';
    public $clientId = '';
    public $privateKeyBase64 = '';
    public $certificateBase64 = '';
    public $token = '';
    public $serverPublicKeys = [];

    public function __construct($clientId, $privateKeyBase64, $certificateBase64){

        $this->clientId = $clientId;

        $this->privateKeyBase64 = $privateKeyBase64;

        $this->certificateBase64 = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $certificateBase64));
    }

    public function setApiBaseUrl($url){
        $this->apiBaseUrl = $url;
    }
    private function requestNonce(){

        return $this->sendRequest($this->apiBaseUrl.'/nonce?timeToLive='.mt_rand(100,200));
    }

    private function base64UrlEncode($data) {

        return str_replace('=', '',  strtr( base64_encode($data), '+/', '-_'));
    }

    private function encodeJson($data) {

        return json_encode($data,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }
    private function createJWS(array $header, array $payload){

        if( ! (isset($header['alg']) && $header['alg'] == 'RS256')){
            throw new \Exception('Cannot create JWS, the supported "alg" is (RS256).');
        }

        $jwtHeader = $this->base64UrlEncode($this->encodeJson($header));

        $jwtPayload = $this->base64UrlEncode($this->encodeJson($payload));

        $signAlg = '';

        if($header['alg'] == 'RS256'){
            $signAlg = 'sha256WithRSAEncryption';
        }

        openssl_sign( $jwtHeader.".".$jwtPayload, $jwtSig,  $this->privateKeyBase64,  $signAlg );

        $jwtSig = $this->base64UrlEncode($jwtSig);

        $jws = $jwtHeader.".".$jwtPayload.".".$jwtSig;

        return $jws;
    }



    private function createJWE(array $header, $publicKey, $payload){

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

        $AAD = $this->base64UrlEncode($this->encodeJson($header));

        $encryptedData = openssl_encrypt($payload, 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag,  $AAD, 16);


        $jwe = $this->base64UrlEncode($this->encodeJson($header)) . 'src' . $this->base64UrlEncode($encryptedKey) . '.' . $this->base64UrlEncode($iv) . '.' . $this->base64UrlEncode($encryptedData) . '.' . $this->base64UrlEncode($tag);


        return $jwe;
    }



    function guidv4($data = null) {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }


    public function sendInvoice(array $invoicesPackets){

        $this->requestToken();

        $result = $this->sendRequest($this->apiBaseUrl.'/invoice', 'POST', $this->token, $invoicesPackets);

        return $result;
    }

    public function createInvoicePacket($requestTraceId, array $header, array $body, array $payments): array
    {

        $datetime = New \DateTime();

        $this->getServerInformation();

        $jwsHeader = [
            'alg' => 'RS256',
            'typ' => 'jose',
            'x5c' => [$this->certificateBase64],
            'sigT' => $datetime->format('Y-m-d').'T'.$datetime->format('H:i:s').'Z+0330',
            'crit' => ['sigT'],
            'cty' => 'text/plain',
        ];

        $invoiceJWS = $this->createJWS($jwsHeader, ['header' => $header, 'body' => $body, 'payments' => $payments]);

        if(in_array('RSA', array_column($this->serverPublicKeys, 'algorithm'))) {

            $index = array_search('RSA', array_column($this->serverPublicKeys, 'algorithm'));

            $serverPublicKey = $this->serverPublicKeys[$index]['key'];

            $serverPublicKeyId =  $this->serverPublicKeys[$index]['id'];

        }else{
            throw new \Exception('the server public key algorithm not supported. the supported algorithm is (RSA)');
        }

        $jweHeader = [
            'alg' => 'RSA-OAEP-256',
            'enc' => 'A256GCM',
            'kid' => $serverPublicKeyId,
        ];

        $data = [
                    'payload' => $this->createJWE($jweHeader, $serverPublicKey, $invoiceJWS),
                    'header' => [
                        'requestTraceId' => $requestTraceId,
                        'fiscalId' => $this->clientId,
                    ],
        ];


        return $data;
    }


    private function requestToken(): string
    {

        $datetime = New \DateTime();

        $nonceResult = $this->requestNonce();

        $header = [
            'alg' => 'RS256',
            'typ' => 'jose',
            'x5c' => [$this->certificateBase64],
            'sigT' => $datetime->format('Y-m-d').'T'.$datetime->format('H:i:s').'Z+0330',
            'crit' => ['sigT'],
            'cty' => 'text/plain',
        ];

        $payload = [
            'nonce' => $nonceResult['nonce'],
            'clientId' => $this->clientId,
        ];

        $token = $this->createJWS($header, $payload);

        $this->token = $token;

        return $this->token;
    }


    public function getFiscalInformation($memoryId){

        $this->requestToken();

        $result = $this->sendRequest($this->apiBaseUrl.'/fiscal-information?economicCode='.$memoryId, 'GET', $this->token);

        return $result;

    }

    public function getTaxPayer($economicCode){

        $this->requestToken();

        $result = $this->sendRequest($this->apiBaseUrl.'/taxpayer?economicCode='.$economicCode, 'GET', $this->token);

        return $result;

    }

    public function inquiryByReferenceId(array $referenceIds, $startDateTime = '', $endDateTime = ''){

        $this->requestToken();

        $params = '';

        foreach ($referenceIds as $referenceId){
            $params .= 'referenceIds='.$referenceId.'&';
        }

        $params =  rtrim($params, '&');

        if($startDateTime){
            $params .= '&start='.urlencode($startDateTime);
        }

        if($endDateTime){
            $params .= '&end='.urlencode($endDateTime);
        }

        $result = $this->sendRequest($this->apiBaseUrl.'/inquiry-by-reference-id?'.$params, 'GET', $this->token);

        return $result;
    }
    public function inquiryByUId(array $UIds, $startDateTime = '', $endDateTime = ''){

        $this->requestToken();

        $params = 'fiscalId='.$this->clientId ;

        foreach ($UIds as $uid){
            $params .= '&uidList='.$uid;
        }

        if($startDateTime){
            $params .= '&start='.urlencode($startDateTime);
        }

        if($endDateTime){
            $params .= '&end='.urlencode($endDateTime);
        }


        $result = $this->sendRequest($this->apiBaseUrl.'/inquiry-by-uid?'.$params, 'GET', $this->token);

        return $result;
    }
    private function getServerInformation(){

        $this->requestToken();

        $result = $this->sendRequest($this->apiBaseUrl.'/server-information', 'GET', $this->token);

        if($result && isset($result['publicKeys'])){

            $this->serverPublicKeys = $result['publicKeys'];

            return $this->serverPublicKeys;
        }

        throw new CommunicateException('Cannot get server information');
    }

    private function sendRequest($url, $method = 'GET', $token = '', $data = []){

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        if($method == 'POST' && $data){

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->encodeJson($data));
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, TRUE);
        //curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $headers = [];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'accept: */*';
        $headers[] = 'Cache-Control: no-cache';

        if($token){
            $headers[] = "Authorization: Bearer ".$token;
        }

        if($headers){
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $res = curl_exec($ch);

        curl_close($ch);

        if($res){
            if($arr = json_decode($res, TRUE, 512, JSON_BIGINT_AS_STRING)){
                return $arr;
            }
        }

        return [];

    }




}