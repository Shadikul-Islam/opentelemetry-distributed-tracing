package com.tracing.order.service;

import java.math.BigDecimal;
import java.util.Locale;
import java.util.UUID;

import com.tracing.order.client.InventoryClient;
import com.tracing.order.exception.NotFoundException;
import com.tracing.order.model.CreateOrderRequest;
import com.tracing.order.model.Customer;
import com.tracing.order.model.InventoryView;
import com.tracing.order.model.OrderRecord;
import com.tracing.order.model.OrderResponse;
import com.tracing.order.model.ReservationResult;
import com.tracing.order.repository.OrderRepository;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

@Service
public class OrderService {

    private static final Logger log = LoggerFactory.getLogger(OrderService.class);

    private final OrderRepository repository;
    private final InventoryClient inventoryClient;

    public OrderService(OrderRepository repository, InventoryClient inventoryClient) {
        this.repository = repository;
        this.inventoryClient = inventoryClient;
    }

    public OrderResponse placeOrder(CreateOrderRequest request) {
        Customer customer = repository.findCustomerById(request.getCustomerId())
                .orElseThrow(() -> new NotFoundException("Unknown customer: " + request.getCustomerId()));

        String orderRef = newOrderRef();

        ReservationResult reservation =
                inventoryClient.reserve(request.getSku(), orderRef, request.getQuantity());

        BigDecimal unitPrice = reservation.getUnitPrice();
        BigDecimal totalAmount = unitPrice.multiply(BigDecimal.valueOf(request.getQuantity()));

        repository.insertOrder(orderRef, customer.getId(), request.getSku(), request.getQuantity(),
                unitPrice, totalAmount, "CONFIRMED");

        log.info("order {} confirmed for customer {} ({} x {})",
                orderRef, customer.getId(), request.getQuantity(), request.getSku());

        OrderResponse response = new OrderResponse();
        response.setOrderRef(orderRef);
        response.setCustomerId(customer.getId());
        response.setCustomerName(customer.getName());
        response.setSku(reservation.getSku());
        response.setProductName(reservation.getProductName());
        response.setQuantity(request.getQuantity());
        response.setUnitPrice(unitPrice);
        response.setTotalAmount(totalAmount);
        response.setStatus("CONFIRMED");
        response.setAvailableQty(reservation.getRemainingQty());
        return response;
    }

    public OrderResponse getOrder(String orderRef) {
        OrderRecord record = repository.findByRef(orderRef)
                .orElseThrow(() -> new NotFoundException("Unknown order: " + orderRef));

        InventoryView stock = inventoryClient.fetchStock(record.getSku());

        OrderResponse response = new OrderResponse();
        response.setOrderRef(record.getOrderRef());
        response.setCustomerId(record.getCustomerId());
        response.setCustomerName(record.getCustomerName());
        response.setSku(record.getSku());
        response.setProductName(stock.getProductName());
        response.setQuantity(record.getQuantity());
        response.setUnitPrice(record.getUnitPrice());
        response.setTotalAmount(record.getTotalAmount());
        response.setStatus(record.getStatus());
        response.setCreatedAt(record.getCreatedAt());
        response.setAvailableQty(stock.getAvailableQty());
        return response;
    }

    private String newOrderRef() {
        return "ORD-" + UUID.randomUUID().toString().substring(0, 8).toUpperCase(Locale.ROOT);
    }
}
