<?php

namespace Inventory\Controller;

use Inventory\Domain\InvalidRequest;
use Inventory\Domain\SkuNotFound;
use Inventory\Repository\InventoryRepository;
use Inventory\Tracing\Span;
use Inventory\Tracing\Tracer;

final class InventoryController
{
    private $repository;

    private $tracer;

    public function __construct(InventoryRepository $repository, Tracer $tracer)
    {
        $this->repository = $repository;
        $this->tracer = $tracer;
    }

    public function show(array $params, array $body)
    {
        $sku = $params['sku'];

        $row = $this->repository->findBySku($sku);
        if ($row === null) {
            throw new SkuNotFound('Unknown sku: ' . $sku);
        }

        return array(200, array(
            'sku' => $row['sku'],
            'productName' => $row['product_name'],
            'unitPrice' => (float) $row['unit_price'],
            'availableQty' => (int) $row['available_qty'],
            'reservedQty' => (int) $row['reserved_qty'],
            'warehouse' => $row['warehouse'],
        ));
    }

    public function reserve(array $params, array $body)
    {
        $sku = $params['sku'];
        $orderRef = isset($body['orderRef']) ? (string) $body['orderRef'] : '';
        $quantity = isset($body['quantity']) ? $body['quantity'] : null;

        if ($orderRef === '') {
            throw new InvalidRequest('orderRef is required');
        }
        if (!is_int($quantity) && !ctype_digit((string) $quantity)) {
            throw new InvalidRequest('quantity must be a positive integer');
        }
        $quantity = (int) $quantity;
        if ($quantity < 1) {
            throw new InvalidRequest('quantity must be at least 1');
        }

        $span = $this->tracer->startSpan('inventory.reserve', Span::KIND_INTERNAL, array(
            'inventory.sku' => $sku,
            'inventory.requested_qty' => $quantity,
            'order.ref' => $orderRef,
        ));

        try {
            $result = $this->repository->reserve($sku, $orderRef, $quantity);
            $span->setAttribute('inventory.remaining_qty', $result['remainingQty']);
            return array(201, $result);
        } catch (\Throwable $e) {
            $span->setStatus(Span::STATUS_ERROR, $e->getMessage());
            $span->recordException($e, Tracer::nowNanos());
            throw $e;
        } finally {
            $this->tracer->endSpan($span);
        }
    }
}
