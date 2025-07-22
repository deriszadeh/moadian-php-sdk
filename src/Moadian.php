<?php

namespace Deriszadeh\Moadian;

use DateTimeInterface;
use Deriszadeh\Moadian\Exceptions\CommunicateException;
use Deriszadeh\Moadian\Services\JsonService;
use Deriszadeh\Moadian\Services\JweService;
use Deriszadeh\Moadian\Services\JwsService;
use Deriszadeh\Moadian\Services\VerhoeffService;

class Moadian
{

    public $apiBaseUrl = 'https://tp.tax.gov.ir/requestsmanager/api/v2';
    public $clientId = '';
    public $privateKeyBase64 = '';
    public $certificateBase64 = '';

    public $token = '';

    public $serverPublicKeys = [];

    private  const CHARACTER_TO_NUMBER_CODING = [
        'A' => 65,
        'B' => 66,
        'C' => 67,
        'D' => 68,
        'E' => 69,
        'F' => 70,
        'G' => 71,
        'H' => 72,
        'I' => 73,
        'J' => 74,
        'K' => 75,
        'L' => 76,
        'M' => 77,
        'N' => 78,
        'O' => 79,
        'P' => 80,
        'Q' => 81,
        'R' => 82,
        'S' => 83,
        'T' => 84,
        'U' => 85,
        'V' => 86,
        'W' => 87,
        'X' => 88,
        'Y' => 89,
        'Z' => 90,
    ];

    public function __construct($clientId, $privateKeyBase64, $certificateBase64){

        $this->clientId = $clientId;

        $this->privateKeyBase64 = $privateKeyBase64;

        $this->certificateBase64 = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $certificateBase64));
    }

    private function requestNonce(){

        return $this->sendRequest($this->apiBaseUrl.'/nonce?timeToLive='.mt_rand(100,200));
    }


    public function sendInvoice(array $invoicesPackets){

        $this->requestToken();

        $result = $this->sendRequest($this->apiBaseUrl.'/invoice', 'POST', $this->token, $invoicesPackets);

        return $result;
    }

    public function createInvoicePacket($requestTraceId, array $header, array $body, array $payments){

        $datetime = New \DateTime();

        if( ! $this->serverPublicKeys){
            $this->getServerInformation();
        }

        $jwsHeader = [
            'alg' => 'RS256',
            'typ' => 'jose',
            'x5c' => [$this->certificateBase64],
            'sigT' => $datetime->format('Y-m-d').'T'.$datetime->format('H:i:s').'Z',
            'crit' => ['sigT'],
            'cty' => 'text/plain',
        ];

        $invoiceJWS = JwsService::create($this->privateKeyBase64, $jwsHeader, ['header' => $header, 'body' => $body, 'payments' => $payments]);

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
                    'payload' => JweService::create($jweHeader, $serverPublicKey, $invoiceJWS),
                    'header' => [
                        'requestTraceId' => $requestTraceId,
                        'fiscalId' => $this->clientId,
                    ],
        ];


        return $data;
    }


    public function requestToken(){

        $datetime = New \DateTime();

        $nonceResult = $this->requestNonce();

        $header = [
            'alg' => 'RS256',
            'typ' => 'jose',
            'x5c' => [$this->certificateBase64],
            'sigT' => $datetime->format('Y-m-d').'T'.$datetime->format('H:i:s').'Z',
            'crit' => ['sigT'],
            'cty' => 'text/plain',
        ];

        $payload = [
            'nonce' => $nonceResult['nonce'],
            'clientId' => $this->clientId,
        ];

        $token = JwsService::create($this->privateKeyBase64, $header, $payload);

        $this->token = $token;

        return $this->token;
    }


    public function getFiscalInformation($memoryId){

        $this->requestToken();

        $result = $this->sendRequest($this->apiBaseUrl.'/fiscal-information?memoryId='.$memoryId, 'GET', $this->token);

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
    public function getServerInformation(){

        $this->requestToken();

        $result = $this->sendRequest($this->apiBaseUrl.'/server-information', 'GET', $this->token);

        if($result && isset($result['publicKeys'])){

            $this->serverPublicKeys = $result['publicKeys'];

            return $this->serverPublicKeys;
        }

        throw new CommunicateException('خطا در دریافت اطلاعات اولیه از سرور مودیان');
    }

    public function sendRequest($url, $method = 'GET', $token = '', $data = []){

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        if($method == 'POST' && $data){

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, JsonService::encode($data));
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

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


    public  function generateInvoiceId(DateTimeInterface $date, int $internalInvoiceId): string
    {
        $daysPastEpoch = $this->getDaysPastEpoch($date);
        $daysPastEpochPadded = str_pad($daysPastEpoch, 6, '0', STR_PAD_LEFT);
        $hexDaysPastEpochPadded = str_pad(dechex($daysPastEpoch), 5, '0', STR_PAD_LEFT);

        $numericClientId = $this->clientIdToNumber($this->clientId);

        $internalInvoiceIdPadded = str_pad($internalInvoiceId, 12, '0', STR_PAD_LEFT);
        $hexInternalInvoiceIdPadded = str_pad(dechex($internalInvoiceId), 10, '0', STR_PAD_LEFT);

        $decimalInvoiceId = $numericClientId . $daysPastEpochPadded . $internalInvoiceIdPadded;

        $checksum = VerhoeffService::checkSum($decimalInvoiceId);

        return strtoupper($this->clientId . $hexDaysPastEpochPadded . $hexInternalInvoiceIdPadded . $checksum);
    }

    private function getDaysPastEpoch(DateTimeInterface $date): int
    {
        return (int)($date->getTimestamp() / (3600 * 24));
    }

    private function clientIdToNumber(string $clientId): string
    {
        $result = '';
        foreach (str_split($clientId) as $char) {
            if (is_numeric($char)) {
                $result .= $char;
            } else {
                $result .= self::CHARACTER_TO_NUMBER_CODING[$char];
            }
        }

        return $result;
    }


}
