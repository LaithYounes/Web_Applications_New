
package com.ecommerce.inventory.repository;

import com.ecommerce.inventory.entity.InventoryReservation;
import com.ecommerce.inventory.entity.InventoryReservation.ReservationStatus;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface InventoryReservationRepository
        extends JpaRepository<InventoryReservation, Long> {

    List<InventoryReservation> findByReservationId(String reservationId);

    List<InventoryReservation> findByReservationIdAndStatus(
        String reservationId,
        ReservationStatus status
    );

    

    boolean existsByReservationId(String reservationId);
}
