<?php

namespace Inventory\Tracing;

final class Tracer
{
    private $context;

    private $exporter;

    private $finished = array();

    private $stack = array();

    private $flushed = false;

    public function __construct(TraceContext $context, OtlpHttpExporter $exporter)
    {
        $this->context = $context;
        $this->exporter = $exporter;
    }

    public function startSpan($name, $kind, array $attributes = array())
    {
        $parentSpanId = $this->currentSpanId();

        $span = new Span(
            $this->context->traceId(),
            TraceContext::randomHex(8),
            $parentSpanId,
            $name,
            $kind,
            self::nowNanos()
        );
        $span->setAttributes($attributes);

        $this->stack[] = $span;

        return $span;
    }

    public function endSpan(Span $span)
    {
        $span->end(self::nowNanos());

        foreach ($this->stack as $index => $open) {
            if ($open === $span) {
                unset($this->stack[$index]);
                $this->stack = array_values($this->stack);
                break;
            }
        }

        $this->finished[] = $span;
    }

    private function currentSpanId()
    {
        $depth = count($this->stack);
        if ($depth > 0) {
            return $this->stack[$depth - 1]->spanId();
        }
        return $this->context->parentSpanId();
    }

    public function traceId()
    {
        return $this->context->traceId();
    }

    public function flush()
    {
        if ($this->flushed) {
            return;
        }
        $this->flushed = true;

        $now = self::nowNanos();
        foreach ($this->stack as $span) {
            $span->end($now);
            $this->finished[] = $span;
        }
        $this->stack = array();

        if (!$this->context->isSampled() || empty($this->finished)) {
            $this->finished = array();
            return;
        }

        $this->exporter->export($this->finished);
        $this->finished = array();
    }

    // Splits microtime()'s string form rather than scaling microtime(true): a
    // float holds ~15 significant digits, a nanosecond epoch needs 19.
    // hrtime() would be simpler but arrived in PHP 7.3.
    public static function nowNanos()
    {
        list($microseconds, $seconds) = explode(' ', microtime());
        return ((int) $seconds) * 1000000000 + (int) round(((float) $microseconds) * 1000000000);
    }
}
