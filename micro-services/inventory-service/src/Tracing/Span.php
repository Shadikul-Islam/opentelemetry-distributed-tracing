<?php

namespace Inventory\Tracing;

final class Span
{
    const KIND_INTERNAL = 1;
    const KIND_SERVER = 2;
    const KIND_CLIENT = 3;

    const STATUS_UNSET = 0;
    const STATUS_ERROR = 2;

    private $traceId;

    private $spanId;

    private $parentSpanId;

    private $name;

    private $kind;

    private $startTimeUnixNano;

    private $endTimeUnixNano;

    private $attributes = array();

    private $statusCode = self::STATUS_UNSET;

    private $statusMessage = '';

    private $events = array();

    public function __construct($traceId, $spanId, $parentSpanId, $name, $kind, $startTimeUnixNano)
    {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentSpanId = $parentSpanId;
        $this->name = $name;
        $this->kind = $kind;
        $this->startTimeUnixNano = $startTimeUnixNano;
    }

    public function setAttributes(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            if ($value !== null) {
                $this->attributes[$key] = $value;
            }
        }
        return $this;
    }

    public function setAttribute($key, $value)
    {
        if ($value !== null) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    public function setStatus($code, $message = '')
    {
        $this->statusCode = $code;
        $this->statusMessage = $message;
        return $this;
    }

    public function recordException(\Throwable $e, $timeUnixNano)
    {
        $this->events[] = array(
            'name' => 'exception',
            'timeUnixNano' => $timeUnixNano,
            'attributes' => array(
                'exception.type' => get_class($e),
                'exception.message' => $e->getMessage(),
                'exception.stacktrace' => $e->getTraceAsString(),
            ),
        );
        return $this;
    }

    public function end($endTimeUnixNano)
    {
        if ($this->endTimeUnixNano === null) {
            $this->endTimeUnixNano = $endTimeUnixNano;
        }
    }

    public function isEnded()
    {
        return $this->endTimeUnixNano !== null;
    }

    public function traceId()
    {
        return $this->traceId;
    }

    public function spanId()
    {
        return $this->spanId;
    }

    public function parentSpanId()
    {
        return $this->parentSpanId;
    }

    public function name()
    {
        return $this->name;
    }

    public function kind()
    {
        return $this->kind;
    }

    public function startTimeUnixNano()
    {
        return $this->startTimeUnixNano;
    }

    public function endTimeUnixNano()
    {
        return $this->endTimeUnixNano;
    }

    public function attributes()
    {
        return $this->attributes;
    }

    public function statusCode()
    {
        return $this->statusCode;
    }

    public function statusMessage()
    {
        return $this->statusMessage;
    }

    public function events()
    {
        return $this->events;
    }
}
