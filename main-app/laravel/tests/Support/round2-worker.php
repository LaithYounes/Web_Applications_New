<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Modules\Products\Services\ProductService;
use App\Support\Performance\CatalogCache;
use Tests\Support\Round2Environment;

try {
    [, $manifest] = Round2Environment::boot();
    [$script, $action, $barrier, $workerId, $productId, $categoryId] = $argv;
    if (! preg_match('/^[a-f0-9]{16}$/D', $barrier) || ! ctype_digit($workerId)) {
        throw new RuntimeException('Unsafe worker barrier.');
    }
    $base = dirname(getenv('ROUND2_TEST_MANIFEST')).'/'.$barrier;
    $service = app(ProductService::class);
    $cache = app(CatalogCache::class);
    file_put_contents($base.'.ready.'.$workerId, 'ready');
    $deadline = microtime(true) + 25;
    while (! is_file($base.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }
        usleep(5000);
    }
    if ($action === 'invalidate') {
        for ($i = 0; $i < 20; $i++) {
            $cache->mutate(fn () => null);
        }
    } elseif ($action === 'write') {
        for ($i = 0; $i < 20; $i++) {
            $service->update((int) $productId, ['name' => 'Concurrent '.$i]);
        }
    } elseif ($action === 'read') {
        for ($i = 0; $i < 40; $i++) {
            $page = $service->getAll((int) $categoryId);
            if ($page->total() !== 1 || $page->count() !== 1 || $page->first()->id !== (int) $productId) {
                throw new RuntimeException('Concurrent read returned incoherent page.');
            }
        }
    } else {
        throw new RuntimeException('Unknown worker action.');
    }
    echo json_encode(['action' => $action, 'ok' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    // Do not print PDO exceptions, whose traces may contain credentials.
    fwrite(STDERR, "Isolated concurrency worker failed.\n");
    exit(1);
}
