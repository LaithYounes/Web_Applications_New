package com.ecommerce.inventory;

import com.ecommerce.inventory.repository.InventoryReservationRepository;
import com.ecommerce.inventory.service.InventoryManagementService;
import com.ecommerce.inventory.service.InventoryManagementService.ReservationAlreadyExistsException;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.condition.EnabledIfEnvironmentVariable;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.context.DynamicPropertyRegistry;
import org.springframework.test.context.DynamicPropertySource;

import java.util.Map;
import java.util.UUID;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicLong;

import static org.junit.jupiter.api.Assertions.*;

// These tests require an explicitly supplied, disposable PostgreSQL database.
// A real database is necessary to verify advisory locks and concurrent transactions.
@SpringBootTest(properties = "spring.grpc.server.port=0")
@EnabledIfEnvironmentVariable(named = "INVENTORY_QA_DB_URL", matches = "jdbc:postgresql:.*")
class ReservationIdempotencyTests {
    private static final AtomicLong PRODUCT_IDS = new AtomicLong(System.currentTimeMillis());

    @DynamicPropertySource
    static void database(DynamicPropertyRegistry registry) {
        registry.add("spring.datasource.url", () -> System.getenv("INVENTORY_QA_DB_URL"));
        registry.add("spring.datasource.username", () -> System.getenv("INVENTORY_QA_DB_USER"));
        registry.add("spring.datasource.password", () -> System.getenv("INVENTORY_QA_DB_PASSWORD"));
    }

    @Autowired InventoryManagementService inventory;
    @Autowired InventoryReservationRepository reservations;

    @Test
    void replayOfLastUnitSucceedsWithoutReservingAgain() {
        long product = stock(1);
        String id = UUID.randomUUID().toString();
        inventory.reserveStock(id, Map.of(product, 1));
        inventory.reserveStock(id, Map.of(product, 1));
        assertEquals(0, inventory.getAvailableQuantity(product));
        assertEquals(1, reservations.findByReservationId(id).size());
        assertTrue(inventory.releaseStock(id));
        assertEquals(1, inventory.getAvailableQuantity(product));
    }

    @Test
    void changedBasketCannotReuseAnId() {
        long a = stock(3);
        long b = stock(3);
        String id = UUID.randomUUID().toString();
        inventory.reserveStock(id, Map.of(a, 1));
        assertThrows(ReservationAlreadyExistsException.class, () -> inventory.reserveStock(id, Map.of(a, 2)));
        assertThrows(ReservationAlreadyExistsException.class, () -> inventory.reserveStock(id, Map.of(b, 1)));
        assertEquals(2, inventory.getAvailableQuantity(a));
        assertEquals(3, inventory.getAvailableQuantity(b));
    }

    @Test
    void finalizedIdsCannotCreateNewHolds() {
        long product = stock(3);
        String released = UUID.randomUUID().toString();
        inventory.reserveStock(released, Map.of(product, 1));
        assertTrue(inventory.releaseStock(released));
        assertThrows(ReservationAlreadyExistsException.class, () -> inventory.reserveStock(released, Map.of(product, 1)));
        String committed = UUID.randomUUID().toString();
        inventory.reserveStock(committed, Map.of(product, 1));
        assertTrue(inventory.commitReservation(committed));
        assertThrows(ReservationAlreadyExistsException.class, () -> inventory.reserveStock(committed, Map.of(product, 1)));
        assertEquals(2, inventory.getAvailableQuantity(product));
    }

    @Test
    void concurrentIdenticalRequestsReserveTheLastUnitOnce() throws Exception {
        long product = stock(1);
        String id = UUID.randomUUID().toString();
        try (var pool = Executors.newVirtualThreadPerTaskExecutor()) {
            var start = new CountDownLatch(1);
            var first = pool.submit(() -> { start.await(); inventory.reserveStock(id, Map.of(product, 1)); return true; });
            var second = pool.submit(() -> { start.await(); inventory.reserveStock(id, Map.of(product, 1)); return true; });
            start.countDown();
            assertTrue(first.get(15, TimeUnit.SECONDS));
            assertTrue(second.get(15, TimeUnit.SECONDS));
        }
        assertEquals(1, reservations.findByReservationId(id).size());
        assertEquals(0, inventory.getAvailableQuantity(product));
    }

    @Test
    void concurrentDifferentBasketsCannotClaimTheSameNewId() throws Exception {
        long a = stock(1);
        long b = stock(1);
        String id = UUID.randomUUID().toString();
        try (var pool = Executors.newVirtualThreadPerTaskExecutor()) {
            var start = new CountDownLatch(1);
            var first = pool.submit(() -> { start.await(); return reserve(id, a); });
            var second = pool.submit(() -> { start.await(); return reserve(id, b); });
            start.countDown();
            assertNotEquals(first.get(15, TimeUnit.SECONDS), second.get(15, TimeUnit.SECONDS));
        }
        assertEquals(1, reservations.findByReservationId(id).size());
        assertEquals(1, inventory.getAvailableQuantity(a) + inventory.getAvailableQuantity(b));
    }

    private boolean reserve(String id, long product) {
        try {
            inventory.reserveStock(id, Map.of(product, 1));
            return true;
        } catch (ReservationAlreadyExistsException expected) {
            return false;
        }
    }

    private long stock(int quantity) {
        long id = PRODUCT_IDS.incrementAndGet();
        inventory.initializeStock(id, quantity);
        return id;
    }
}
