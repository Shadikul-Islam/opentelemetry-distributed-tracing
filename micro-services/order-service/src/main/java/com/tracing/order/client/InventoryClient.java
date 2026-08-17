package com.tracing.order.client;

import java.util.HashMap;
import java.util.Map;

import com.tracing.order.config.InventoryProperties;
import com.tracing.order.exception.InsufficientStockException;
import com.tracing.order.exception.NotFoundException;
import com.tracing.order.exception.UpstreamUnavailableException;
import com.tracing.order.model.InventoryView;
import com.tracing.order.model.ReservationResult;

import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Component;
import org.springframework.web.client.HttpClientErrorException;
import org.springframework.web.client.ResourceAccessException;
import org.springframework.web.client.RestTemplate;

@Component
public class InventoryClient {

    private final RestTemplate restTemplate;
    private final String baseUrl;

    public InventoryClient(RestTemplate inventoryRestTemplate, InventoryProperties properties) {
        this.restTemplate = inventoryRestTemplate;
        this.baseUrl = properties.getBaseUrl();
    }

    public InventoryView fetchStock(String sku) {
        try {
            return restTemplate.getForObject(baseUrl + "/api/inventory/{sku}", InventoryView.class, sku);
        } catch (HttpClientErrorException.NotFound e) {
            throw new NotFoundException("Unknown sku: " + sku);
        } catch (ResourceAccessException e) {
            throw new UpstreamUnavailableException("inventory-service is unreachable", e);
        }
    }

    public ReservationResult reserve(String sku, String orderRef, int quantity) {
        Map<String, Object> body = new HashMap<>();
        body.put("orderRef", orderRef);
        body.put("quantity", quantity);

        try {
            return restTemplate.postForObject(
                    baseUrl + "/api/inventory/{sku}/reserve", body, ReservationResult.class, sku);
        } catch (HttpClientErrorException e) {
            if (e.getStatusCode() == HttpStatus.NOT_FOUND) {
                throw new NotFoundException("Unknown sku: " + sku);
            }
            if (e.getStatusCode() == HttpStatus.CONFLICT) {
                throw new InsufficientStockException("Not enough stock for sku " + sku);
            }
            throw e;
        } catch (ResourceAccessException e) {
            throw new UpstreamUnavailableException("inventory-service is unreachable", e);
        }
    }
}
