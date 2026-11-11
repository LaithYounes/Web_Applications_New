<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$queries = [];
Illuminate\Support\Facades\DB::listen(static function ($query) use (&$queries): void {
    $queries[] = $query->sql;
});

$cache = app(App\Support\Performance\CatalogCache::class);
$cache->hits = 0;
$cache->misses = 0;

$request1 = Illuminate\Http\Request::create('/api/v1/products?per_page=12', 'GET');
$response1 = $http->handle($request1);
$count1 = count($queries);
echo 'status1='.$response1->getStatusCode().PHP_EOL;
echo 'queries1='.$count1.PHP_EOL;
echo 'misses1='.$cache->misses.PHP_EOL;
echo 'hits1='.$cache->hits.PHP_EOL;
echo 'lastKey='.$cache->lastKey.PHP_EOL;

$queries = [];
$request2 = Illuminate\Http\Request::create('/api/v1/products?per_page=12', 'GET');
$response2 = $http->handle($request2);
$count2 = count($queries);
echo 'status2='.$response2->getStatusCode().PHP_EOL;
echo 'queries2='.$count2.PHP_EOL;
echo 'misses2='.$cache->misses.PHP_EOL;
echo 'hits2='.$cache->hits.PHP_EOL;

$payload = Illuminate\Support\Facades\Cache::get($cache->lastKey);
echo 'cached_is_array='.(is_array($payload) ? '1' : '0').PHP_EOL;
echo 'cached_total='.(is_array($payload) ? (string) ($payload['total'] ?? 'n/a') : 'n/a').PHP_EOL;
