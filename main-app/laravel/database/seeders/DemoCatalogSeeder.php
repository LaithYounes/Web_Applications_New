<?php

namespace Database\Seeders;

use App\Modules\Inventory\Exceptions\InventoryServiceException;
use App\Modules\Inventory\Services\InventoryGrpcClient;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

class DemoCatalogSeeder extends Seeder
{
    private const CATALOG = [
        [
            'name' => 'Smartphones',
            'description' => 'Smartphones for everyday use, work, and photography.',
            'image' => 'phone.png',
            'products' => [
                'iphone15' => ['Apple iPhone 15', 'A 6.1-inch smartphone with a 48 MP main camera and USB-C charging.', '649.00', 12],
                'galaxyA55' => ['Samsung Galaxy A55', 'A midrange 5G smartphone with a 6.6-inch AMOLED display and a 50 MP main camera.', '329.00', 1],
                'redmiNote13' => ['Xiaomi Redmi Note 13', 'An everyday smartphone with a 6.67-inch AMOLED display and a 5,000 mAh battery.', '179.00', 16],
            ],
        ],
        [
            'name' => 'Computers and Accessories',
            'description' => 'Laptops, peripherals, and chargers for work and study.',
            'image' => 'computer.png',
            'products' => [
                'ideapad' => ['Lenovo IdeaPad Slim 3', 'A lightweight laptop for browsing, video calls, and everyday office tasks.', '479.00', 8],
                'logitechM185' => ['Logitech M185', 'A compact wireless mouse with a USB receiver for laptops and desktops.', '18.50', 24],
                'ankerNano' => ['Anker Nano USB-C Charger', 'A compact USB-C wall charger for phones and other portable devices.', '24.00', 30],
            ],
        ],
        [
            'name' => 'Audio',
            'description' => 'Headphones and portable speakers for commuting, travel, and home listening.',
            'image' => 'audio.png',
            'products' => [
                'sonyHeadphones' => ['Sony WH-1000XM5', 'Wireless over-ear headphones with active noise cancellation and multipoint Bluetooth.', '299.00', 1],
                'jblFlip6' => ['JBL Flip 6', 'A portable Bluetooth speaker with a water-resistant design for outdoor listening.', '109.00', 10],
                'soundcoreQ45' => ['Soundcore Space Q45', 'Wireless over-ear headphones with adaptive noise cancellation for travel and work.', '119.00', 9],
            ],
        ],
        [
            'name' => 'Kitchen Appliances',
            'description' => 'Small appliances for cooking, food prep, and home espresso.',
            'image' => 'home.png',
            'products' => [
                'philipsAirfryer' => ['Philips Airfryer Essential', 'A countertop air fryer with a removable basket for quick everyday meals.', '129.00', 6],
                'delonghiDedica' => ['DeLonghi Dedica', 'A slim espresso machine with a steam wand for cappuccinos and lattes.', '199.00', 5],
                'braunMultiquick' => ['Braun MultiQuick 5', 'An immersion blender for pureeing soups and blending sauces directly in the pot.', '59.50', 7],
            ],
        ],
        [
            'name' => 'Smartwatches and Fitness Trackers',
            'description' => 'Wearables for tracking daily activity, workouts, and sleep.',
            'image' => 'wearable.png',
            'products' => [
                'appleWatchSE' => ['Apple Watch SE', 'An Apple smartwatch for fitness tracking, notifications, and everyday activity.', '219.00', 7],
                'garminForerunner' => ['Garmin Forerunner 55', 'A GPS running watch with pace alerts and suggested workouts.', '159.00', 4],
                'xiaomiBand8' => ['Xiaomi Smart Band 8', 'A slim fitness tracker with an AMOLED display for monitoring activity and sleep.', '39.00', 0],
            ],
        ],
    ];

    public static function creationKey(string $product): string
    {
        return hash('sha256', "demo-catalog:{$product}");
    }

    public function run(ProductService $products, InventoryGrpcClient $inventory): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('The demo catalog can only be seeded in local or testing environments.');
        }

        retry(
            3,
            fn () => $inventory->getStock(1),
            500,
            fn (Throwable $error) => $error instanceof InventoryServiceException,
        );

        foreach (self::CATALOG as $entry) {
            $category = Category::firstOrCreate(
                ['name' => $entry['name']],
                ['description' => $entry['description']],
            );
            $image = __DIR__.'/fixtures/'.$entry['image'];

            foreach ($entry['products'] as $identifier => [$name, $description, $price, $stock]) {
                $key = self::creationKey($identifier);
                $product = Product::withTrashed()->where('creation_key', $key)->first();

                if ($product !== null) {
                    if (!$product->trashed()) {
                        $products->finishInitialStock($product);
                    }

                    continue;
                }

                $products->create([
                    'category_id' => $category->id,
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'image' => new UploadedFile($image, $entry['image'], 'image/png', null, true),
                    'initial_quantity' => $stock,
                ], $key);
            }
        }
    }
}
