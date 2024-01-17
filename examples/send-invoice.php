<?php

use Deriszadeh\Moadian\Moadian;

$invoiceGregorianDatetTime = '2023-12-30 12:10:10';

$clientId = '######';

$privateKey = <<<EOD
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDQ2McyBhoa4mLW
......
6ngy7kHjluQZ8p9W7pYQ6ZPet2UUlBWv/yPV9+4HTKA3RF2RnUUTQhhyElzkc30h
EusYwpjEcuU7E6y/QekSsoU=
-----END PRIVATE KEY-----
EOD;

$publicKey = <<<EOD
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0NjHMgYaGuJi1lVwJSWD
.....
0PLd+pC03r4e7Nf+BKblEckfyPG0LIe5cYUpa1eh9XO14YUw6bmWVBAtqAuVzl+T
AQIDAQAB
-----END PUBLIC KEY-----
EOD;

$cert = "MIIFdjCCBF6gAwIBAgIKKt7hHAABABfzwTANBgkqhkiG9w0BAQsFADCB3jELMAkG
.......
Qc4eW8rHfFj/NigOf9wa0S/rWgv85rnyFIj/z54ZTgq9mamOuET38Xicq0wMhZTV
/fr6q+ntpkpTgQ==";



try {

    $moadian = new Moadian($clientId, $privateKey, $cert);

    $invoiceDateTime = new \DateTime($invoiceGregorianDatetTime);

    $invoiceHeader = [
        'taxid' => $moadian->generateInvoiceId($invoiceDateTime, 1),
        // 'inno' => '',
        'indatim' => $invoiceDateTime->getTimestamp() * 1000,
        'inty' => 2, // 1|2|3
        'ins' => 1, //اصلی / اصلاحی / ...
        'inp' => 1,
        'tins' => '00000000000', //شناسه ملی فروشنده
        'tob' => 2, // نوع شخص خریدار در الگوی نوع دوم اختیاریه
        'bid' => '',
        'tinb' => '', // شماره اقتصادی خریدار
        'tprdis' => 10000, // مجموع مبلغ قبل از کسر تخفیف
        'tdis' => 0, // مجموع تخفیف
        'tadis' => 0, // مجموع مبلغ پس از کسر تخفیف
        'tvam' => 900, // مجموع مالیات ارزش افزوده
        'tbill' => 10900, //مجموع صورتحساب
        'setm' => 1, // روش تسویه
    ];
    $invoiceBody = [[
        'sstid' => '2720000114542',
        'sstt' => 'بسته نرم افزار ماشین حساب',
        'mu' => 1627, //واحد اندازه گیری
        'am' => 1, //تعداد
        'fee' => 10000,
        'prdis' => 10000, //قبل از تخفیف
        'dis' => 0, //تخفیف
        'adis' => 0, //بعد از تخفیف
        'vra' => 9, //نرخ مالیات
        'vam' => 900, //مالیات
        'tsstam' => 10900, //مبلغ کل
    ]];

    $invoicePayment = [];

    $invoicePackets = [];

    $uid =  SimpleGuidv4Service::generate();

    $invoicePackets[] = $moadian->createInvoicePacket($uid, $invoiceHeader, $invoiceBody, $invoicePayment);

    $res = $moadian->sendInvoice($invoicePackets);

    if($res && is_array($res) && isset($res['result'])){

        sleep(3);

        $datetime = new DateTime();

        $todayDate = $datetime->format('Y-m-d');

        var_dump($moadian->inquiryByUId([$uid],
            $todayDate.'T00:00:00.000000000+03:30',
            $todayDate.'T23:59:59.123456789+03:30'
        ));

    }


}catch (\Exception $ex){

    echo 'ERROR: Cannot send invoice';
    echo "\r\n";
    var_dump($ex->getMessage());
}
