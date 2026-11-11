<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$readHost = config('database.connections.mysql.read.host.0');
$writeHost = config('database.connections.mysql.write.host.0');
echo 'config_read_host='.$readHost.PHP_EOL;
echo 'config_write_host='.$writeHost.PHP_EOL;

$writePdo = DB::connection()->getPdo();
$readPdo = DB::connection()->getReadPdo();

$write = $writePdo->query('SELECT @@server_id AS server_id, @@hostname AS hostname, @@read_only AS read_only')->fetch(PDO::FETCH_OBJ);
$read = $readPdo->query('SELECT @@server_id AS server_id, @@hostname AS hostname, @@read_only AS read_only')->fetch(PDO::FETCH_OBJ);

echo 'write_server_id='.$write->server_id.' hostname='.$write->hostname.' read_only='.$write->read_only.PHP_EOL;
echo 'read_server_id='.$read->server_id.' hostname='.$read->hostname.' read_only='.$read->read_only.PHP_EOL;

$ordersPrimary = (int) DB::table('orders')->useWritePdo()->count();
$ordersRead = (int) DB::table('orders')->count();
echo 'orders_via_write_pdo='.$ordersPrimary.PHP_EOL;
echo 'orders_via_read='.$ordersRead.PHP_EOL;

if ((int) $write->server_id !== 1) {
    fwrite(STDERR, "Expected write server_id=1\n");
    exit(1);
}
if ((int) $read->server_id !== 2) {
    fwrite(STDERR, "Expected read server_id=2 (replica)\n");
    exit(1);
}

echo 'ROUTING_OK'.PHP_EOL;
