
package com.ecommerce.inventory.entity;

import jakarta.persistence.*;
import jakarta.persistence.CheckConstraint;
import java.time.LocalDateTime;



@Entity
@Table(
    name = "inventory_stock",
    uniqueConstraints = {
        @UniqueConstraint(
            name = "uk_inventory_stock_product",
            columnNames = "product_id"
        )
    },
    check = {
        @CheckConstraint(
            name = "chk_inventory_total_quantity",
            constraint = "total_quantity >= 0"
        ),
        @CheckConstraint(
            name = "chk_inventory_reserved_quantity",
            constraint = "reserved_quantity >= 0 AND reserved_quantity <= total_quantity"
        )
    }
)
public class InventoryStock {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    @Column(name = "product_id", nullable = false, unique = true)
    private Long productId;

    @Column(name = "total_quantity", nullable = false)
    private Integer totalQuantity;

    @Column(name = "reserved_quantity", nullable = false)
    private Integer reservedQuantity = 0;

    @Column(name = "created_at", nullable = false, updatable = false)
    private LocalDateTime createdAt;

    @Column(name = "updated_at", nullable = false)
    private LocalDateTime updatedAt;

    public InventoryStock() {
    }

    @PrePersist
    protected void onCreate() {
        LocalDateTime now = LocalDateTime.now();
        createdAt = now;
        updatedAt = now;
    }

    @PreUpdate
    protected void onUpdate() {
        updatedAt = LocalDateTime.now();
    }

    public Long getId() {
        return id;
    }

    public Long getProductId() {
        return productId;
    }

    public void setProductId(Long productId) {
        this.productId = productId;
    }

    public Integer getTotalQuantity() {
        return totalQuantity;
    }

    public void setTotalQuantity(Integer totalQuantity) {
        this.totalQuantity = totalQuantity;
    }

    public Integer getReservedQuantity() {
        return reservedQuantity;
    }

    public void setReservedQuantity(Integer reservedQuantity) {
        this.reservedQuantity = reservedQuantity;
    }

    @Transient
    public Integer getAvailableQuantity() {
        return totalQuantity - reservedQuantity;
    }
}
