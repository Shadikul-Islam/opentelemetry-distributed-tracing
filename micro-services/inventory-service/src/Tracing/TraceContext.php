<?php

namespace Inventory\Tracing;

final class TraceContext
{
    const FLAG_SAMPLED = 0x01;

    private $traceId;

    private $parentSpanId;

    private $sampled;

    private function __construct($traceId, $parentSpanId, $sampled)
    {
        $this->traceId = $traceId;
        $this->parentSpanId = $parentSpanId;
        $this->sampled = $sampled;
    }

    public static function fromServer(array $server)
    {
        $header = self::readHeader($server);
        if ($header !== null) {
            $parsed = self::parse($header);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return new self(self::randomHex(16), null, true);
    }

    private static function readHeader(array $server)
    {
        if (isset($server['HTTP_TRACEPARENT']) && $server['HTTP_TRACEPARENT'] !== '') {
            return $server['HTTP_TRACEPARENT'];
        }
        return null;
    }

    private static function parse($header)
    {
        $parts = explode('-', trim($header));
        if (count($parts) < 4) {
            return null;
        }

        list($version, $traceId, $spanId, $flags) = $parts;

        if (!preg_match('/^[0-9a-f]{2}$/', $version) || $version === 'ff') {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{32}$/', $traceId) || $traceId === str_repeat('0', 32)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{16}$/', $spanId) || $spanId === str_repeat('0', 16)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{2}$/', $flags)) {
            return null;
        }

        $sampled = (hexdec($flags) & self::FLAG_SAMPLED) === self::FLAG_SAMPLED;

        return new self($traceId, $spanId, $sampled);
    }

    public function traceId()
    {
        return $this->traceId;
    }

    public function parentSpanId()
    {
        return $this->parentSpanId;
    }

    public function isSampled()
    {
        return $this->sampled;
    }

    public static function randomHex($bytes)
    {
        return bin2hex(random_bytes($bytes));
    }
}
