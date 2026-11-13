<?php

namespace App\Support\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Chunked synthetic order dataset writer for the performance environment.
 * Inserts users/catalog/orders/order_items directly — never calls OrderService or gRPC.
 */
class PerfOrderDatasetGenerator
{
    public const DEFAULT_PASSWORD = 'PerfLoad2026!';

    /** @var list<string> */
    private const STATUSES = [
        'delivered',
        'delivered',
        'delivered',
        'delivered',
        'delivered',
        'shipped',
        'shipped',
        'confirmed',
        'processing',
        'pending',
        'cancelled',
    ];

    public function __construct(
        private readonly PerfEnvironmentGuard $guard = new PerfEnvironmentGuard,
    ) {}

    /**
     * @param  array{
     *     count?: int,
     *     chunk?: int,
     *     users?: int,
     *     categories?: int,
     *     products_per_category?: int,
     *     seed?: int,
     *     skip_guard?: bool,
     *     progress?: callable(string): void
     * }  $options
     * @return array{
     *     requested: int,
     *     inserted_orders: int,
     *     inserted_items: int,
     *     users_ensured: int,
     *     products_ensured: int,
     *     orders_before: int,
     *     orders_after: int
     * }
     */
    public function generate(array $options = []): array
    {
        $count = max(0, (int) ($options['count'] ?? 200_000));
        $chunk = max(1, min(5_000, (int) ($options['chunk'] ?? 2_000)));
        $usersTarget = max(1, (int) ($options['users'] ?? 5_000));
        $categories = max(1, (int) ($options['categories'] ?? 10));
        $productsPerCategory = max(1, (int) ($options['products_per_category'] ?? 10));
        $seed = (int) ($options['seed'] ?? 20261110);
        $progress = $options['progress'] ?? static function (string $message): void {};

        if (! ($options['skip_guard'] ?? false)) {
            $this->guard->assertSafeToMutate();
        }

        if ($count === 0) {
            $ordersBefore = (int) DB::table('orders')->count();

            return [
                'requested' => 0,
                'inserted_orders' => 0,
                'inserted_items' => 0,
                'users_ensured' => 0,
                'products_ensured' => 0,
                'orders_before' => $ordersBefore,
                'orders_after' => $ordersBefore,
            ];
        }

        $ordersBefore = (int) DB::table('orders')->count();
        $progress("orders_before={$ordersBefore}");

        $productSnapshots = $this->ensureCatalog($categories, $productsPerCategory, $progress);
        $userIds = $this->ensureUsers($usersTarget, $progress);

        if ($productSnapshots === [] || $userIds === []) {
            throw new RuntimeException('Perf generator requires at least one product and one user.');
        }

        $insertedOrders = 0;
        $insertedItems = 0;
        $batchIndex = 0;
        // Prefix with current row count so re-runs stay unique without truncate.
        $runPrefix = sprintf('r%08d', $ordersBefore);

        while ($insertedOrders < $count) {
            $batchSize = min($chunk, $count - $insertedOrders);
            $batchSeed = $seed + ($batchIndex * 1_000_003) + $ordersBefore;
            $stats = $this->insertOrderChunk(
                $batchSize,
                $batchIndex,
                $batchSeed,
                $runPrefix,
                $userIds,
                $productSnapshots,
            );
            $insertedOrders += $stats['orders'];
            $insertedItems += $stats['items'];
            $batchIndex++;
            $progress(sprintf(
                'chunk=%d inserted_orders=%d/%d items=%d',
                $batchIndex,
                $insertedOrders,
                $count,
                $insertedItems
            ));
        }

        $ordersAfter = (int) DB::table('orders')->count();

        return [
            'requested' => $count,
            'inserted_orders' => $insertedOrders,
            'inserted_items' => $insertedItems,
            'users_ensured' => count($userIds),
            'products_ensured' => count($productSnapshots),
            'orders_before' => $ordersBefore,
            'orders_after' => $ordersAfter,
        ];
    }

    /**
     * @return list<array{id: int, name: string, price: string}>
     */
    public function ensureCatalog(int $categories, int $productsPerCategory, callable $progress): array
    {
        $now = now()->toDateTimeString();

        for ($c = 1; $c <= $categories; $c++) {
            $name = sprintf('Perf Category %02d', $c);
            $exists = DB::table('categories')->where('name', $name)->exists();
            if (! $exists) {
                DB::table('categories')->insert([
                    'name' => $name,
                    'description' => 'Synthetic catalog category for performance baselines.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $categoryRows = DB::table('categories')
            ->where('name', 'like', 'Perf Category %')
            ->orderBy('id')
            ->get(['id', 'name']);

        $createdProducts = 0;
        foreach ($categoryRows as $index => $category) {
            for ($p = 1; $p <= $productsPerCategory; $p++) {
                $creationKey = hash('sha256', sprintf('perf-catalog:c%d:p%d', $category->id, $p));
                $exists = DB::table('products')->where('creation_key', $creationKey)->exists();
                if ($exists) {
                    continue;
                }

                $price = number_format(9.99 + (($index * $productsPerCategory + $p) % 90), 2, '.', '');
                $productAt = now()->subDays(($index * $productsPerCategory) + $p)->toDateTimeString();
                DB::table('products')->insert([
                    'category_id' => $category->id,
                    'name' => sprintf('Perf Product C%d-P%02d', $category->id, $p),
                    'description' => 'Synthetic product for Requirement-3 read baselines.',
                    'price' => $price,
                    'image_path' => 'products/perf-placeholder.png',
                    'pending_initial_quantity' => null,
                    'creation_key' => $creationKey,
                    'creation_fingerprint' => null,
                    'created_at' => $productAt,
                    'updated_at' => $productAt,
                ]);
                $createdProducts++;
            }
        }

        $progress("catalog_products_created={$createdProducts}");

        return DB::table('products')
            ->where('name', 'like', 'Perf Product %')
            ->orderBy('id')
            ->get(['id', 'name', 'price'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'price' => (string) $row->price,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function ensureUsers(int $targetCount, callable $progress): array
    {
        $existing = DB::table('users')
            ->where('email', 'like', 'perf.user.%@example.test')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $needed = max(0, $targetCount - count($existing));
        if ($needed === 0) {
            $progress('users_ensured='.count($existing));

            return array_slice($existing, 0, $targetCount);
        }

        $passwordHash = Hash::make(self::DEFAULT_PASSWORD);
        $now = now()->toDateTimeString();
        $maxSuffix = 0;
        $emails = DB::table('users')
            ->where('email', 'like', 'perf.user.%@example.test')
            ->pluck('email');
        foreach ($emails as $email) {
            if (preg_match('/^perf\.user\.(\d+)@example\.test$/', (string) $email, $matches) === 1) {
                $maxSuffix = max($maxSuffix, (int) $matches[1]);
            }
        }
        $startIndex = $maxSuffix + 1;
        $batch = [];

        for ($i = 0; $i < $needed; $i++) {
            $n = $startIndex + $i;
            $batch[] = [
                'name' => sprintf('Perf User %05d', $n),
                'email' => sprintf('perf.user.%05d@example.test', $n),
                'email_verified_at' => $now,
                'password' => $passwordHash,
                'role' => 'customer',
                'remember_token' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('users')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('users')->insert($batch);
        }

        $ids = DB::table('users')
            ->where('email', 'like', 'perf.user.%@example.test')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $progress('users_ensured='.count($ids));

        return array_slice($ids, 0, $targetCount);
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<array{id: int, name: string, price: string}>  $products
     * @return array{orders: int, items: int}
     */
    private function insertOrderChunk(
        int $batchSize,
        int $batchIndex,
        int $batchSeed,
        string $runPrefix,
        array $userIds,
        array $products,
    ): array {
        $now = now();
        $orderRows = [];
        $plannedItems = [];
        $productCount = count($products);

        for ($i = 0; $i < $batchSize; $i++) {
            $seq = ($batchIndex * 10_000_000) + $i;
            $userId = $this->pickUserId($userIds, $seq, $batchSeed);
            $status = self::STATUSES[$seq % count(self::STATUSES)];
            // Unique across ~15M orders: day-of-window + second-of-day. Matches ORDER BY created_at.
            $createdAt = $now->copy()
                ->subDays($seq % 180)
                ->subSeconds(intdiv($seq, 180) % 86400);
            $itemCount = 1 + ($seq % 3);
            if (($seq % 17) === 0) {
                $itemCount = min($productCount, 4 + ($seq % 2));
            }
            $itemCount = min($itemCount, $productCount);

            $linePlans = [];
            $totalCents = 0;
            $usedProducts = [];
            $line = 0;
            while (count($linePlans) < $itemCount && $line < $productCount) {
                $product = $products[($seq + $line * 31 + $batchSeed) % $productCount];
                $line++;
                if (isset($usedProducts[$product['id']])) {
                    continue;
                }
                $usedProducts[$product['id']] = true;
                $quantity = 1 + (($seq + $line) % 3);
                $unitCents = $this->priceToCents($product['price']);
                $subtotalCents = $unitCents * $quantity;
                $totalCents += $subtotalCents;
                $linePlans[] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'unit_price' => number_format($unitCents / 100, 2, '.', ''),
                    'quantity' => $quantity,
                    'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
                    'created_at' => $createdAt->toDateTimeString(),
                    'updated_at' => $createdAt->toDateTimeString(),
                ];
            }

            $reservationId = sprintf('perf-%s-b%08d-i%08d', $runPrefix, $batchIndex, $i);
            $orderRows[] = [
                'user_id' => $userId,
                'status' => $status,
                'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                'reservation_id' => $reservationId,
                'created_at' => $createdAt->toDateTimeString(),
                'updated_at' => $createdAt->toDateTimeString(),
            ];
            $plannedItems[$reservationId] = $linePlans;
        }

        $itemCountOut = 0;

        DB::transaction(function () use ($orderRows, $plannedItems, &$itemCountOut): void {
            DB::table('orders')->insert($orderRows);

            $reservationIds = array_keys($plannedItems);
            $idMap = DB::table('orders')
                ->whereIn('reservation_id', $reservationIds)
                ->pluck('id', 'reservation_id');

            $itemRows = [];
            foreach ($plannedItems as $reservationId => $lines) {
                $orderId = $idMap[$reservationId] ?? null;
                if ($orderId === null) {
                    throw new RuntimeException("Missing order id for reservation {$reservationId}");
                }
                foreach ($lines as $line) {
                    $itemRows[] = [
                        'order_id' => (int) $orderId,
                        ...$line,
                    ];
                }
            }

            foreach (array_chunk($itemRows, 1_000) as $itemChunk) {
                DB::table('order_items')->insert($itemChunk);
            }

            $itemCountOut = count($itemRows);
        });

        return [
            'orders' => count($orderRows),
            'items' => $itemCountOut,
        ];
    }

    /**
     * Benchmark login perf.user.00001 (lowest id) receives ~40% of orders.
     * The next ~5% of accounts receive ~35%. The remainder is a long tail.
     *
     * @param  list<int>  $userIds  ordered by id ascending
     */
    private function pickUserId(array $userIds, int $seq, int $seed): int
    {
        $count = count($userIds);
        $hot = max(1, (int) ceil($count * 0.05));
        $bucket = ($seq + $seed) % 100;

        if ($bucket < 40) {
            return $userIds[0];
        }

        if ($bucket < 75) {
            return $userIds[($seq + $seed) % $hot];
        }

        return $userIds[($seq * 13 + $seed) % $count];
    }

    private function priceToCents(string $price): int
    {
        $parts = explode('.', $price);
        $whole = (int) ($parts[0] ?? 0);
        $fraction = str_pad($parts[1] ?? '0', 2, '0');

        return ($whole * 100) + (int) substr($fraction, 0, 2);
    }
}
