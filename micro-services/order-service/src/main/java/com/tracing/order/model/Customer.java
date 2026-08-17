package com.tracing.order.model;

public class Customer {

    private final long id;
    private final String name;
    private final String email;
    private final String tier;

    public Customer(long id, String name, String email, String tier) {
        this.id = id;
        this.name = name;
        this.email = email;
        this.tier = tier;
    }

    public long getId() {
        return id;
    }

    public String getName() {
        return name;
    }

    public String getEmail() {
        return email;
    }

    public String getTier() {
        return tier;
    }
}
