package com.tracing.order.repository;

import java.math.BigDecimal;
import java.util.List;
import java.util.Optional;

import com.tracing.order.model.Customer;
import com.tracing.order.model.OrderRecord;

import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.jdbc.core.RowMapper;
import org.springframework.stereotype.Repository;

@Repository
public class OrderRepository {

    private static final RowMapper<Customer> CUSTOMER_MAPPER = (rs, rowNum) -> new Customer(
            rs.getLong("id"),
            rs.getString("name"),
            rs.getString("email"),
            rs.getString("tier"));

    private static final RowMapper<OrderRecord> ORDER_MAPPER = (rs, rowNum) -> new OrderRecord(
            rs.getString("order_ref"),
            rs.getLong("customer_id"),
            rs.getString("customer_name"),
            rs.getString("sku"),
            rs.getInt("quantity"),
            rs.getBigDecimal("unit_price"),
            rs.getBigDecimal("total_amount"),
            rs.getString("status"),
            rs.getTimestamp("created_at").toLocalDateTime());

    private final JdbcTemplate jdbcTemplate;

    public OrderRepository(JdbcTemplate jdbcTemplate) {
        this.jdbcTemplate = jdbcTemplate;
    }

    public Optional<Customer> findCustomerById(long customerId) {
        List<Customer> rows = jdbcTemplate.query(
                "SELECT id, name, email, tier FROM customers WHERE id = ?",
                CUSTOMER_MAPPER,
                customerId);
        return rows.stream().findFirst();
    }

    public void insertOrder(String orderRef, long customerId, String sku, int quantity,
                            BigDecimal unitPrice, BigDecimal totalAmount, String status) {
        jdbcTemplate.update(
                "INSERT INTO orders (order_ref, customer_id, sku, quantity, unit_price, total_amount, status) "
                        + "VALUES (?, ?, ?, ?, ?, ?, ?)",
                orderRef, customerId, sku, quantity, unitPrice, totalAmount, status);
    }

    public Optional<OrderRecord> findByRef(String orderRef) {
        List<OrderRecord> rows = jdbcTemplate.query(
                "SELECT o.order_ref, o.customer_id, c.name AS customer_name, o.sku, o.quantity, "
                        + "o.unit_price, o.total_amount, o.status, o.created_at "
                        + "FROM orders o JOIN customers c ON c.id = o.customer_id "
                        + "WHERE o.order_ref = ?",
                ORDER_MAPPER,
                orderRef);
        return rows.stream().findFirst();
    }
}
