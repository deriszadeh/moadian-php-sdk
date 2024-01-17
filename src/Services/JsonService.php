<?php
namespace Deriszadeh\Moadian\Services;

class JsonService{

    public static function encode($data){
        return json_encode($data,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    public static function decode($data){
        return json_decode($data, TRUE, 512, JSON_BIGINT_AS_STRING);
    }

}