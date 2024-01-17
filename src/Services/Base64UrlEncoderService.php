<?php
namespace Deriszadeh\Moadian\Services;

class Base64UrlEncoderService{

    public static function encode($data){
        return str_replace('=', '',  strtr( base64_encode($data), '+/', '-_'));
    }

    public static function decode($data){
        return base64_decode(strtr($data, '-_', '+/'));
    }

}