
package com.ecommerce.inventory.service;

import com.ecommerce.inventory.entity.InventoryReservation;
import com.ecommerce.inventory.entity.InventoryReservation.ReservationStatus;
import com.ecommerce.inventory.entity.InventoryStock;
import com.ecommerce.inventory.repository.InventoryReservationRepository;
import com.ecommerce.inventory.repository.InventoryStockRepository;

import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;

@Service
public class InventoryManagementService {

    private final InventoryStockRepository stockRepository;
    private final InventoryReservationRepository reservationRepository;

    public InventoryManagementService(
            InventoryStockRepository stockRepository,
            InventoryReservationRepository reservationRepository) {

        this.stockRepository = stockRepository;
        this.reservationRepository = reservationRepository;
    }

    // Get available quantity for one product
    @Transactional(readOnly = true)
    public int getAvailableQuantity(Long productId) {

        if (productId == null || productId <= 0) {
            throw new IllegalArgumentException("Invalid product ID.");
        }

        return stockRepository.findByProductId(productId)
                .map(InventoryStock::getAvailableQuantity)
                .orElse(0);
    }


    @Transactional
    public int initializeStock(Long productId, Integer quantity) {
        validateStockInput(productId, quantity);

        stockRepository.initializeIfAbsent(productId, quantity);
        return stockRepository.findByProductId(productId).orElseThrow().getAvailableQuantity();
    }

    // Add stock for a product (admin operation)
    @Transactional
    public int addStock(Long productId, Integer quantity) {

        validateStockInput(productId, quantity);

        InventoryStock stock = stockRepository
                .findByProductIdForUpdate(productId)
                .orElseGet(() -> {
                    InventoryStock newStock = new InventoryStock();
                    newStock.setProductId(productId);
                    newStock.setTotalQuantity(0);
                    newStock.setReservedQuantity(0);
                    return newStock;
                });

        stock.setTotalQuantity(
                stock.getTotalQuantity() + quantity
        );

        stockRepository.save(stock);

        return stock.getAvailableQuantity();
    }

    private void validateStockInput(Long productId, Integer quantity) {
        if (productId == null || productId <= 0) {
            throw new IllegalArgumentException("Invalid product ID.");
        }

        if (quantity == null || quantity <= 0) {
            throw new IllegalArgumentException("Quantity must be greater than zero.");
        }
    }

    // Reserve multiple products in one transaction
    @Transactional
    public void reserveStock(
            String reservationId,
            Map<Long, Integer> requestedItems) {

        validateReservationRequest(reservationId, requestedItems);

        if (reservationRepository.existsByReservationId(reservationId)) {
            throw new ReservationAlreadyExistsException(
                    "Reservation ID already exists."
            );
        }

        // Sort product IDs to acquire locks in a consistent order
        Map<Long, Integer> sortedItems = new TreeMap<>(requestedItems);

        Map<Long, InventoryStock> lockedStocks = new LinkedHashMap<>();

        // Lock and check every product before changing any quantity
        for (Map.Entry<Long, Integer> item : sortedItems.entrySet()) {

            Long productId = item.getKey();
            Integer requestedQuantity = item.getValue();

            InventoryStock stock = stockRepository
                    .findByProductIdForUpdate(productId)
                    .orElseThrow(() -> new InsufficientStockException(
                            "Product " + productId + " does not exist in inventory."
                    ));

            if (stock.getAvailableQuantity() < requestedQuantity) {
                throw new InsufficientStockException(
                        "Insufficient stock for product " + productId
                );
            }

            lockedStocks.put(productId, stock);
        }

        // All products are available: update stock quantities
        List<InventoryReservation> reservations = new ArrayList<>();

        for (Map.Entry<Long, Integer> item : sortedItems.entrySet()) {

            Long productId = item.getKey();
            Integer quantity = item.getValue();

            InventoryStock stock = lockedStocks.get(productId);

            stock.setReservedQuantity(
                    stock.getReservedQuantity() + quantity
            );

            InventoryReservation reservation = new InventoryReservation();

            reservation.setReservationId(reservationId);
            reservation.setProductId(productId);
            reservation.setQuantity(quantity);
            reservation.setStatus(ReservationStatus.ACTIVE);

            reservations.add(reservation);
        }

        stockRepository.saveAll(lockedStocks.values());
        reservationRepository.saveAll(reservations);
    }

// Permanently commit a previous reservation
@Transactional
public boolean commitReservation(String reservationId) {

    if (reservationId == null || reservationId.isBlank()) {
        throw new IllegalArgumentException("Invalid reservation ID.");
    }

    List<InventoryReservation> existingReservations =
            reservationRepository.findByReservationId(reservationId);

    if (existingReservations.isEmpty()) {
        return false;
    }

    List<InventoryReservation> activeReservations =
            reservationRepository.findByReservationIdAndStatus(
                    reservationId,
                    ReservationStatus.ACTIVE
            );

    // Already committed: do not deduct stock again
    if (activeReservations.isEmpty()) {

        boolean alreadyCommitted = existingReservations.stream()
                .allMatch(reservation ->
                        reservation.getStatus() == ReservationStatus.COMMITTED
                );

        return alreadyCommitted;
    }

    // Lock related stock records in sorted order
    List<Long> productIds = activeReservations.stream()
            .map(InventoryReservation::getProductId)
            .distinct()
            .sorted()
            .toList();

    Map<Long, InventoryStock> lockedStocks = new LinkedHashMap<>();

    for (Long productId : productIds) {

        InventoryStock stock = stockRepository
                .findByProductIdForUpdate(productId)
                .orElseThrow(() -> new IllegalStateException(
                        "Inventory record not found for product " + productId
                ));

        lockedStocks.put(productId, stock);
    }

    /*
     * Re-read active reservations after acquiring stock locks.
     * This prevents concurrent commit/release requests from
     * processing the same reservation twice.
     */
    activeReservations =
            reservationRepository.findByReservationIdAndStatus(
                    reservationId,
                    ReservationStatus.ACTIVE
            );

    if (activeReservations.isEmpty()) {

        List<InventoryReservation> currentReservations =
                reservationRepository.findByReservationId(reservationId);

        return currentReservations.stream()
                .allMatch(reservation ->
                        reservation.getStatus() == ReservationStatus.COMMITTED
                );
    }

    // Validate all reserved quantities before changing anything
    for (InventoryReservation reservation : activeReservations) {

        InventoryStock stock = lockedStocks.get(
                reservation.getProductId()
        );

        if (stock == null ||
                stock.getReservedQuantity() < reservation.getQuantity() ||
                stock.getTotalQuantity() < reservation.getQuantity()) {

            throw new IllegalStateException(
                    "Invalid stock quantity for product "
                            + reservation.getProductId()
            );
        }
    }

    // Deduct physical stock and reserved stock
    for (InventoryReservation reservation : activeReservations) {

        InventoryStock stock = lockedStocks.get(
                reservation.getProductId()
        );

        stock.setTotalQuantity(
                stock.getTotalQuantity() - reservation.getQuantity()
        );

        stock.setReservedQuantity(
                stock.getReservedQuantity() - reservation.getQuantity()
        );

        reservation.setStatus(ReservationStatus.COMMITTED);
    }

    stockRepository.saveAll(lockedStocks.values());
    reservationRepository.saveAll(activeReservations);

    return true;
}


    
    // Release a previous reservation
@Transactional
public boolean releaseStock(String reservationId) {

    if (reservationId == null || reservationId.isBlank()) {
        throw new IllegalArgumentException("Invalid reservation ID.");
    }

    List<InventoryReservation> existingReservations =
            reservationRepository.findByReservationId(reservationId);

    if (existingReservations.isEmpty()) {
        return false;
    }

    // Already released: repeated release is successful.
    // Committed reservations must never be released.
    if (existingReservations.stream().noneMatch(
            reservation -> reservation.getStatus() == ReservationStatus.ACTIVE
    )) {
        return existingReservations.stream().allMatch(
                reservation -> reservation.getStatus() == ReservationStatus.RELEASED
        );
    }

    // Do not process inconsistent mixed reservation states.
    if (!existingReservations.stream().allMatch(
            reservation -> reservation.getStatus() == ReservationStatus.ACTIVE
    )) {
        return false;
    }

    // Lock related stock records in sorted order.
    List<Long> productIds = existingReservations.stream()
            .map(InventoryReservation::getProductId)
            .distinct()
            .sorted()
            .toList();

    Map<Long, InventoryStock> lockedStocks = new LinkedHashMap<>();

    for (Long productId : productIds) {

        InventoryStock stock = stockRepository
                .findByProductIdForUpdate(productId)
                .orElseThrow(() -> new IllegalStateException(
                        "Inventory record not found for product " + productId
                ));

        lockedStocks.put(productId, stock);
    }

    // Re-read reservations after acquiring stock locks.
    List<InventoryReservation> currentReservations =
            reservationRepository.findByReservationId(reservationId);

    // Another request may have completed the release while we were waiting.
    if (currentReservations.stream().allMatch(
            reservation -> reservation.getStatus() == ReservationStatus.RELEASED
    )) {
        return true;
    }

    // Reject committed or inconsistent reservations.
    if (!currentReservations.stream().allMatch(
            reservation -> reservation.getStatus() == ReservationStatus.ACTIVE
    )) {
        return false;
    }

    // Validate all reserved quantities before changing anything.
    for (InventoryReservation reservation : currentReservations) {

        InventoryStock stock = lockedStocks.get(
                reservation.getProductId()
        );

        if (stock == null ||
                stock.getReservedQuantity() < reservation.getQuantity()) {

            throw new IllegalStateException(
                    "Invalid reserved quantity for product "
                            + reservation.getProductId()
            );
        }
    }

    // Release reserved quantities.
    for (InventoryReservation reservation : currentReservations) {

        InventoryStock stock = lockedStocks.get(
                reservation.getProductId()
        );

        stock.setReservedQuantity(
                stock.getReservedQuantity() - reservation.getQuantity()
        );

        reservation.setStatus(ReservationStatus.RELEASED);
    }

    stockRepository.saveAll(lockedStocks.values());
    reservationRepository.saveAll(currentReservations);

    return true;
}
    // Validate incoming reservation data
    private void validateReservationRequest(
            String reservationId,
            Map<Long, Integer> requestedItems) {

        if (reservationId == null ||
                reservationId.isBlank() ||
                reservationId.length() > 100) {

            throw new IllegalArgumentException("Invalid reservation ID.");
        }

        if (requestedItems == null || requestedItems.isEmpty()) {
            throw new IllegalArgumentException(
                    "Reservation must contain at least one product."
            );
        }

        for (Map.Entry<Long, Integer> item : requestedItems.entrySet()) {

            if (item.getKey() == null || item.getKey() <= 0) {
                throw new IllegalArgumentException("Invalid product ID.");
            }

            if (item.getValue() == null || item.getValue() <= 0) {
                throw new IllegalArgumentException(
                        "Product quantity must be greater than zero."
                );
            }
        }
    }

    // Exception for insufficient stock
    public static class InsufficientStockException
            extends RuntimeException {

        public InsufficientStockException(String message) {
            super(message);
        }
    }

    // Exception for duplicate reservation IDs
    public static class ReservationAlreadyExistsException
            extends RuntimeException {

        public ReservationAlreadyExistsException(String message) {
            super(message);
        }
    }
}
