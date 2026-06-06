<?php

namespace App\Support;

class MockApiUrls
{
    public static function order(string $orderId = '{{ input.body.orderId }}'): string
    {
        return self::baseUrl().'/api/mock/orders/'.$orderId;
    }

    public static function notifications(): string
    {
        return self::baseUrl().'/api/mock/notifications';
    }

    public static function orderStatus(string $orderId = '{{ input.body.orderId }}'): string
    {
        return self::order($orderId).'/status';
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
