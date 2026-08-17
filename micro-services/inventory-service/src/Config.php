<?php

namespace Inventory;

final class Config
{
    public static function serviceName()
    {
        return self::env('OTEL_SERVICE_NAME', 'inventory-service');
    }

    public static function serviceVersion()
    {
        return self::env('SERVICE_VERSION', '1.0.0');
    }

    public static function deploymentEnvironment()
    {
        return self::env('DEPLOYMENT_ENVIRONMENT', 'local');
    }

    public static function otlpTracesEndpoint()
    {
        $explicit = self::env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', '');
        if ($explicit !== '') {
            return $explicit;
        }
        $base = rtrim(self::env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://otel-collector:4318'), '/');
        return $base . '/v1/traces';
    }

    public static function otlpTimeoutMs()
    {
        return (int) self::env('OTEL_EXPORTER_OTLP_TIMEOUT', '2000');
    }

    public static function dbHost()
    {
        return self::env('INVENTORY_DB_HOST', 'mysql');
    }

    public static function dbPort()
    {
        return (int) self::env('INVENTORY_DB_PORT', '3306');
    }

    public static function dbName()
    {
        return self::env('INVENTORY_DB_NAME', 'inventorydb');
    }

    public static function dbUser()
    {
        return self::env('INVENTORY_DB_USER', 'inventory_user');
    }

    public static function dbPassword()
    {
        return self::env('INVENTORY_DB_PASSWORD', 'inventory_pass');
    }

    private static function env($key, $default)
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        return $default;
    }
}
