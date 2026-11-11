
package com.ecommerce.inventory.repository;

import com.ecommerce.inventory.entity.InventoryReservation;
import com.ecommerce.inventory.entity.InventoryReservation.ReservationStatus;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;

public interface InventoryReservationRepository
        extends JpaRepository<InventoryReservation, Long> {

    // Also serializes first use of an ID, before any reservation row exists.
    // The lock is released by PostgreSQL on transaction commit/rollback.
    @Query(value = "SELECT 1 FROM pg_advisory_xact_lock(hashtextextended(:reservationId, 0))", nativeQuery = true)
    Integer lockReservationId(@Param("reservationId") String reservationId);

    List<InventoryReservation> findByReservationId(String reservationId);

    List<InventoryReservation> findByReservationIdAndStatus(
        String reservationId,
        ReservationStatus status
    );

    

    boolean existsByReservationId(String reservationId);
}
