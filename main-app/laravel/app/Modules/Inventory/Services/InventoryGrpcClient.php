<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Inventory\Exceptions\InventoryServiceException;
use Grpc\ChannelCredentials;
use Inventory\AddStockRequest;
use Inventory\InventoryServiceClient;
use Inventory\ReleaseStockRequest;
use Inventory\ReserveStockRequest;
use Inventory\StockItem;
use Throwable;
use Inventory\CommitReservationRequest;
use Inventory\GetStockRequest;

class InventoryGrpcClient
{
    private function client(): InventoryServiceClient
    {
        return new InventoryServiceClient(
            config('services.inventory.host'),
            [
                'credentials' => ChannelCredentials::createInsecure(),
            ]
        );
    }

    public function addStock(int $productId, int $quantity): int
    {
        return $this->writeStock($productId, $quantity, initialize: false);
    }

    public function initializeStock(int $productId, int $quantity): int
    {
        return $this->writeStock($productId, $quantity, initialize: true);
    }

    private function writeStock(int $productId, int $quantity, bool $initialize): int
    {
        $request = (new AddStockRequest())
            ->setProductId($productId)
            ->setQuantity($quantity);

        try {
            $client = $this->client();
            $options = ['timeout' => config('services.inventory.timeout')];
            $call = $initialize
                ? $client->InitializeStock($request, [], $options)
                : $client->AddStock($request, [], $options);
            [$response, $status] = $call->wait();

            $this->checkStatus($status);

            if (!$response || !$response->getSuccess()) {
                throw new InventoryRejectedException(
                    $response?->getMessage() ?: 'Failed to add stock.'
                );
            }

            return $response->getAvailableQuantity();
        } catch (InventoryRejectedException | InventoryServiceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InventoryServiceException(
                'Could not communicate with inventory service.',
                0,
                $exception
            );
        }
    }

    public function reserveStock(string $reservationId, array $items): void
    {
        $stockItems = [];

        foreach ($items as $item) {
            $stockItems[] = (new StockItem())
                ->setProductId((int) $item['product_id'])
                ->setQuantity((int) $item['quantity']);
        }

        $request = (new ReserveStockRequest())
            ->setReservationId($reservationId)
            ->setItems($stockItems);

        try {
            [$response, $status] = $this->client()
                ->ReserveStock($request, [], [
                    'timeout' => config('services.inventory.timeout'),
                ])
                ->wait();

            $this->checkStatus($status);

            if (!$response || !$response->getSuccess()) {
                throw new InventoryRejectedException(
                    $response?->getMessage() ?: 'Insufficient stock.'
                );
            }
        } catch (InventoryRejectedException | InventoryServiceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InventoryServiceException(
                'Could not communicate with inventory service.',
                0,
                $exception
            );
        }
    }

    public function releaseStock(string $reservationId): void
    {
        $request = (new ReleaseStockRequest())
            ->setReservationId($reservationId);

        try {
            [$response, $status] = $this->client()
                ->ReleaseStock($request, [], [
                    'timeout' => config('services.inventory.timeout'),
                ])
                ->wait();

            $this->checkStatus($status);

            if (!$response || !$response->getSuccess()) {
                throw new InventoryRejectedException(
                    $response?->getMessage() ?: 'Failed to release stock.'
                );
            }
        } catch (InventoryRejectedException | InventoryServiceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InventoryServiceException(
                'Could not communicate with inventory service.',
                0,
                $exception
            );
        }
    }

    public function getStock(int $productId): int
{
    $request = (new GetStockRequest())
        ->setProductId($productId);

    try {
        [$response, $status] = $this->client()
            ->GetStock($request, [], [
                'timeout' => config('services.inventory.timeout'),
            ])
            ->wait();

        $this->checkStatus($status);

        if (!$response) {
            throw new InventoryServiceException(
                'Invalid response from inventory service.'
            );
        }

        return $response->getAvailableQuantity();

    } catch (InventoryRejectedException | InventoryServiceException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        throw new InventoryServiceException(
            'Could not communicate with inventory service.',
            0,
            $exception
        );
    }
}

    private function checkStatus($status): void
    {
        if ($status->code !== \Grpc\STATUS_OK) {
            throw new InventoryServiceException(
                'Inventory service error: ' . $status->details
            );
        }
    }
public function commitReservation(string $reservationId): void
{
    $request = (new CommitReservationRequest())
        ->setReservationId($reservationId);

    try {
        [$response, $status] = $this->client()
            ->CommitReservation($request, [], [
                'timeout' => config('services.inventory.timeout'),
            ])
            ->wait();

        $this->checkStatus($status);

        if (!$response || !$response->getSuccess()) {
            throw new InventoryRejectedException(
                $response?->getMessage() ?: 'Failed to commit reservation.'
            );
        }
    } catch (InventoryRejectedException | InventoryServiceException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        throw new InventoryServiceException(
            'Could not communicate with inventory service.',
            0,
            $exception
        );
    }
}
}
