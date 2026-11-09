package com.ecommerce.inventory.grpc;

import com.ecommerce.inventory.proto.GetStockRequest;
import com.ecommerce.inventory.proto.GetStockResponse;
import com.ecommerce.inventory.proto.InventoryServiceGrpc;
import com.ecommerce.inventory.proto.ReleaseStockRequest;
import com.ecommerce.inventory.proto.ReleaseStockResponse;
import com.ecommerce.inventory.proto.ReserveStockRequest;
import com.ecommerce.inventory.proto.ReserveStockResponse;
import com.ecommerce.inventory.proto.StockItem;
import com.ecommerce.inventory.proto.AddStockRequest;
import com.ecommerce.inventory.proto.AddStockResponse;
import com.ecommerce.inventory.proto.CommitReservationRequest;
import com.ecommerce.inventory.proto.CommitReservationResponse;
import com.ecommerce.inventory.service.InventoryManagementService;

import io.grpc.Status;
import io.grpc.stub.StreamObserver;

import org.springframework.stereotype.Service;

import java.util.LinkedHashMap;
import java.util.Map;

@Service
public class InventoryGrpcService
        extends InventoryServiceGrpc.InventoryServiceImplBase {

    private final InventoryManagementService inventoryService;

    public InventoryGrpcService(
            InventoryManagementService inventoryService) {

        this.inventoryService = inventoryService;
    }

    // Get available quantity for one product
    @Override
    public void getStock(
            GetStockRequest request,
            StreamObserver<GetStockResponse> responseObserver) {

        try {
            int availableQuantity =
                    inventoryService.getAvailableQuantity(
                            request.getProductId()
                    );

            GetStockResponse response = GetStockResponse.newBuilder()
                    .setProductId(request.getProductId())
                    .setAvailableQuantity(availableQuantity)
                    .build();

            responseObserver.onNext(response);
            responseObserver.onCompleted();

        } catch (IllegalArgumentException exception) {

            responseObserver.onError(
                    Status.INVALID_ARGUMENT
                            .withDescription(exception.getMessage())
                            .asRuntimeException()
            );

        } catch (Exception exception) {

            responseObserver.onError(
                    Status.INTERNAL
                            .withDescription("Failed to retrieve stock.")
                            .asRuntimeException()
            );
        }
    }

    // Reserve multiple products in one operation
    @Override
    public void reserveStock(
            ReserveStockRequest request,
            StreamObserver<ReserveStockResponse> responseObserver) {

        try {
            Map<Long, Integer> requestedItems = new LinkedHashMap<>();

            for (StockItem item : request.getItemsList()) {

                Long productId = item.getProductId();
                Integer quantity = item.getQuantity();

                if (requestedItems.containsKey(productId)) {
                    throw new IllegalArgumentException(
                            "Duplicate product ID in reservation request."
                    );
                }

                requestedItems.put(productId, quantity);
            }

            inventoryService.reserveStock(
                    request.getReservationId(),
                    requestedItems
            );

            ReserveStockResponse response =
                    ReserveStockResponse.newBuilder()
                            .setSuccess(true)
                            .setMessage("Stock reserved successfully.")
                            .setReservationId(request.getReservationId())
                            .build();

            responseObserver.onNext(response);
            responseObserver.onCompleted();

        } catch (InventoryManagementService.InsufficientStockException exception) {

            sendReservationFailure(
                    request,
                    responseObserver,
                    exception.getMessage()
            );

        } catch (InventoryManagementService.ReservationAlreadyExistsException exception) {

            sendReservationFailure(
                    request,
                    responseObserver,
                    exception.getMessage()
            );

        } catch (IllegalArgumentException exception) {

            responseObserver.onError(
                    Status.INVALID_ARGUMENT
                            .withDescription(exception.getMessage())
                            .asRuntimeException()
            );

        } catch (Exception exception) {

            responseObserver.onError(
                    Status.INTERNAL
                            .withDescription("Failed to reserve stock.")
                            .asRuntimeException()
            );
        }
    }

    // Release a previous reservation
    @Override
    public void releaseStock(
            ReleaseStockRequest request,
            StreamObserver<ReleaseStockResponse> responseObserver) {

        try {
            boolean released = inventoryService.releaseStock(
                    request.getReservationId()
            );

            ReleaseStockResponse response =
                    ReleaseStockResponse.newBuilder()
                            .setSuccess(released)
                            .setMessage(
                                    released
                                            ? "Reservation released successfully."
                                            : "Reservation not found."
                            )
                            .build();

            responseObserver.onNext(response);
            responseObserver.onCompleted();

        } catch (IllegalArgumentException exception) {

            responseObserver.onError(
                    Status.INVALID_ARGUMENT
                            .withDescription(exception.getMessage())
                            .asRuntimeException()
            );

        } catch (Exception exception) {

            responseObserver.onError(
                    Status.INTERNAL
                            .withDescription("Failed to release reservation.")
                            .asRuntimeException()
            );
        }
    }


// Permanently commit a reservation
@Override
public void commitReservation(
        CommitReservationRequest request,
        StreamObserver<CommitReservationResponse> responseObserver) {

    try {

        boolean committed = inventoryService.commitReservation(
                request.getReservationId()
        );

        CommitReservationResponse response =
                CommitReservationResponse.newBuilder()
                        .setSuccess(committed)
                        .setMessage(
                                committed
                                        ? "Reservation committed successfully."
                                        : "Reservation could not be committed."
                        )
                        .build();

        responseObserver.onNext(response);
        responseObserver.onCompleted();

    } catch (IllegalArgumentException exception) {

        responseObserver.onError(
                Status.INVALID_ARGUMENT
                        .withDescription(exception.getMessage())
                        .asRuntimeException()
        );

    } catch (Exception exception) {

        responseObserver.onError(
                Status.INTERNAL
                        .withDescription("Failed to commit reservation.")
                        .asRuntimeException()
        );
    }
}


    // Add stock for a product
    @Override
    public void addStock(
            AddStockRequest request,
            StreamObserver<AddStockResponse> responseObserver) {
        writeStock(request, responseObserver, false);
    }

    @Override
    public void initializeStock(
            AddStockRequest request,
            StreamObserver<AddStockResponse> responseObserver) {
        writeStock(request, responseObserver, true);
    }

    private void writeStock(
            AddStockRequest request,
            StreamObserver<AddStockResponse> responseObserver,
            boolean initialize) {

        try {
            int availableQuantity = initialize
                    ? inventoryService.initializeStock(request.getProductId(), request.getQuantity())
                    : inventoryService.addStock(request.getProductId(), request.getQuantity());

            AddStockResponse response =
                    AddStockResponse.newBuilder()
                            .setSuccess(true)
                            .setMessage(initialize ? "Stock initialized successfully." : "Stock added successfully.")
                            .setProductId(request.getProductId())
                            .setAvailableQuantity(availableQuantity)
                            .build();

            responseObserver.onNext(response);
            responseObserver.onCompleted();

        } catch (IllegalArgumentException exception) {

            responseObserver.onError(
                    Status.INVALID_ARGUMENT
                            .withDescription(exception.getMessage())
                            .asRuntimeException()
            );

        } catch (Exception exception) {

            responseObserver.onError(
                    Status.INTERNAL
                            .withDescription("Failed to add stock.")
                            .asRuntimeException()
            );
        }
    }


    private void sendReservationFailure(
            ReserveStockRequest request,
            StreamObserver<ReserveStockResponse> responseObserver,
            String message) {

        ReserveStockResponse response =
                ReserveStockResponse.newBuilder()
                        .setSuccess(false)
                        .setMessage(message)
                        .setReservationId(request.getReservationId())
                        .build();

        responseObserver.onNext(response);
        responseObserver.onCompleted();
    }
}
