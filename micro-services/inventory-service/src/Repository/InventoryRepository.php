<?php

namespace Inventory\Repository;

use Inventory\Db\Database;
use Inventory\Domain\InsufficientStock;
use Inventory\Domain\SkuNotFound;

final class InventoryRepository
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findBySku($sku)
    {
        return $this->db->selectOne(
            'SELECT sku, product_name, unit_price, available_qty, reserved_qty, warehouse
               FROM inventory
              WHERE sku = :sku',
            array('sku' => $sku),
            'SELECT',
            'inventory'
        );
    }

    public function reserve($sku, $orderRef, $quantity)
    {
        return $this->db->transaction(function (Database $db) use ($sku, $orderRef, $quantity) {
            $row = $db->selectOne(
                'SELECT sku, product_name, unit_price, available_qty, reserved_qty
                   FROM inventory
                  WHERE sku = :sku
                    FOR UPDATE',
                array('sku' => $sku),
                'SELECT',
                'inventory'
            );

            if ($row === null) {
                throw new SkuNotFound('Unknown sku: ' . $sku);
            }

            $available = (int) $row['available_qty'];
            if ($available < $quantity) {
                throw new InsufficientStock(sprintf(
                    'Only %d unit(s) of %s available, %d requested',
                    $available, $sku, $quantity
                ));
            }

            // Two placeholders for one value: with emulated prepares off, PDO
            // rejects a named parameter used twice (SQLSTATE HY093).
            $db->execute(
                'UPDATE inventory
                    SET available_qty = available_qty - :taken,
                        reserved_qty  = reserved_qty  + :held
                  WHERE sku = :sku',
                array('taken' => $quantity, 'held' => $quantity, 'sku' => $sku),
                'UPDATE',
                'inventory'
            );

            $db->execute(
                'INSERT INTO reservations (sku, order_ref, quantity)
                      VALUES (:sku, :order_ref, :quantity)',
                array('sku' => $sku, 'order_ref' => $orderRef, 'quantity' => $quantity),
                'INSERT',
                'reservations'
            );

            return array(
                'sku' => $row['sku'],
                'productName' => $row['product_name'],
                'unitPrice' => (float) $row['unit_price'],
                'reservedQty' => $quantity,
                'remainingQty' => $available - $quantity,
                'orderRef' => $orderRef,
            );
        });
    }
}
