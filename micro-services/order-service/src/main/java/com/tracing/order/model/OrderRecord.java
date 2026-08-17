package com.tracing.order.model;

import java.math.BigDecimal;
import java.time.LocalDateTime;

public class OrderRecord {

    private final String orderRef;
    private final long customerId;
    private final String customerName;
    private final String sku;
    private final int quantity;
    private final BigDecimal unitPrice;
    private final BigDecimal totalAmount;
    private final String status;
    private final LocalDateTime createdAt;

    public OrderRecord(String orderRef, long customerId, String customerName, String sku, int quantity,
                       BigDecimal unitPrice, BigDecimal totalAmount, String status, LocalDateTime createdAt) {
        this.orderRef = orderRef;
        this.customerId = customerId;
        this.customerName = customerName;
        this.sku = sku;
        this.quantity = quantity;
        this.unitPrice = unitPrice;
        this.totalAmount = totalAmount;
        this.status = status;
        this.createdAt = createdAt;
    }

    public String getOrderRef() {
        return orderRef;
    }

    public long getCustomerId() {
        return customerId;
    }

    public String getCustomerName() {
        return customerName;
    }

    public String getSku() {
        return sku;
    }

    public int getQuantity() {
        return quantity;
    }

    public BigDecimal getUnitPrice() {
        return unitPrice;
    }

    public BigDecimal getTotalAmount() {
        return totalAmount;
    }

    public String getStatus() {
        return status;
    }

    public LocalDateTime getCreatedAt() {
        return createdAt;
    }
}
