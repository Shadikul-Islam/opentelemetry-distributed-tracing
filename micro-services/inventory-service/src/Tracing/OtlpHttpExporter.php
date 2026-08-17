<?php

namespace Inventory\Tracing;

final class OtlpHttpExporter
{
    private $endpoint;

    private $resourceAttributes;

    private $scopeName;

    private $scopeVersion;

    private $timeoutMs;

    public function __construct($endpoint, array $resourceAttributes, $scopeName, $scopeVersion, $timeoutMs = 2000)
    {
        $this->endpoint = rtrim($endpoint, '/');
        $this->resourceAttributes = $resourceAttributes;
        $this->scopeName = $scopeName;
        $this->scopeVersion = $scopeVersion;
        $this->timeoutMs = $timeoutMs;
    }

    public function export(array $spans)
    {
        if (empty($spans)) {
            return true;
        }

        $payload = json_encode($this->buildPayload($spans));
        if ($payload === false) {
            error_log('[tracing] failed to encode span batch: ' . json_last_error_msg());
            return false;
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
        ));

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[tracing] export failed: ' . $error);
            return false;
        }
        if ($status < 200 || $status >= 300) {
            error_log('[tracing] collector returned HTTP ' . $status . ': ' . $response);
            return false;
        }

        return true;
    }

    private function buildPayload(array $spans)
    {
        $encoded = array();
        foreach ($spans as $span) {
            $encoded[] = $this->encodeSpan($span);
        }

        return array(
            'resourceSpans' => array(
                array(
                    'resource' => array(
                        'attributes' => $this->encodeAttributes($this->resourceAttributes),
                    ),
                    'scopeSpans' => array(
                        array(
                            'scope' => array(
                                'name' => $this->scopeName,
                                'version' => $this->scopeVersion,
                            ),
                            'spans' => $encoded,
                        ),
                    ),
                ),
            ),
        );
    }

    private function encodeSpan(Span $span)
    {
        // traceId/spanId go on the wire as hex, not base64, and 64-bit values
        // as strings. Both are OTLP/JSON requirements.
        $encoded = array(
            'traceId' => $span->traceId(),
            'spanId' => $span->spanId(),
            'name' => $span->name(),
            'kind' => $span->kind(),
            'startTimeUnixNano' => (string) $span->startTimeUnixNano(),
            'endTimeUnixNano' => (string) $span->endTimeUnixNano(),
            'attributes' => $this->encodeAttributes($span->attributes()),
            'status' => array('code' => $span->statusCode()),
        );

        if ($span->parentSpanId() !== null) {
            $encoded['parentSpanId'] = $span->parentSpanId();
        }
        if ($span->statusMessage() !== '') {
            $encoded['status']['message'] = $span->statusMessage();
        }

        $events = $span->events();
        if (!empty($events)) {
            $encodedEvents = array();
            foreach ($events as $event) {
                $encodedEvents[] = array(
                    'name' => $event['name'],
                    'timeUnixNano' => (string) $event['timeUnixNano'],
                    'attributes' => $this->encodeAttributes($event['attributes']),
                );
            }
            $encoded['events'] = $encodedEvents;
        }

        return $encoded;
    }

    private function encodeAttributes(array $attributes)
    {
        $encoded = array();
        foreach ($attributes as $key => $value) {
            $encoded[] = array(
                'key' => $key,
                'value' => $this->encodeValue($value),
            );
        }
        return $encoded;
    }

    private function encodeValue($value)
    {
        if (is_bool($value)) {
            return array('boolValue' => $value);
        }
        if (is_int($value)) {
            return array('intValue' => (string) $value);
        }
        if (is_float($value)) {
            return array('doubleValue' => $value);
        }
        if (is_array($value)) {
            $values = array();
            foreach ($value as $item) {
                $values[] = $this->encodeValue($item);
            }
            return array('arrayValue' => array('values' => $values));
        }
        return array('stringValue' => (string) $value);
    }
}
