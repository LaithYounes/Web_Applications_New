
package com.ecommerce.inventory.repository;

import com.ecommerce.inventory.entity.InventoryStock;
import jakarta.persistence.LockModeType;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Modifying;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.Optional;

public interface InventoryStockRepository
        extends JpaRepository<InventoryStock, Long> {

    Optional<InventoryStock> findByProductId(Long productId);

    @Modifying
    @Query(value = """
        INSERT INTO inventory_stock (product_id, total_quantity, reserved_quantity, created_at, updated_at)
        VALUES (:productId, :quantity, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT (product_id) DO NOTHING
        """, nativeQuery = true)
    void initializeIfAbsent(@Param("productId") Long productId, @Param("quantity") Integer quantity);

    @Lock(LockModeType.PESSIMISTIC_WRITE)
    @Query("""
        SELECT stock
        FROM InventoryStock stock
        WHERE stock.productId = :productId
    """)
    Optional<InventoryStock> findByProductIdForUpdate(
        @Param("productId") Long productId
    );
}
