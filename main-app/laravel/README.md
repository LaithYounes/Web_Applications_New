<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Product API setup

Docker Compose configures the upload limits and creates the public storage link when the app starts.
For local PHP on Windows, run the following from this directory after installing dependencies.
If an old checkout has an empty regular file at `public/storage`, remove the placeholder before creating the link.

```powershell
if (!(Test-Path public/storage -PathType Container)) {
    Push-Location public
    New-Item -ItemType SymbolicLink -Path storage -Target ../storage/app/public
    Pop-Location
}
$env:PHP_INI_SCAN_DIR = (Resolve-Path .\docker\php).Path
php artisan serve --no-reload
```

Run `php artisan migrate` for the Laravel database and start the updated inventory service before creating products.
Set `APP_URL` to the public API origin, for example `http://localhost:8000`, so returned image URLs resolve.
When running Laravel outside Docker, set `INVENTORY_GRPC_HOST` to the inventory service address reachable from the host.

Product uploads accept JPG, PNG and WebP files up to 5 MiB. Set `Idempotency-Key` on
`POST /api/v1/products` and reuse it with the same multipart body if the request fails
due to inventory availability. Product IDs identify the initial stock operation, so retrying it
does not add stock twice. To retry all pending products after restoring the inventory service, run
`php artisan products:retry-initial-stock` or `docker compose exec app php artisan products:retry-initial-stock`.

To run the Products & Categories folder in the root Postman collection, use a local test database,
set `baseUrl` and `productsAdminToken`, and use the repository root as Postman's working directory.
If Postman asks for file selection, select the corresponding files in `postman/fixtures`.

## Local demo data

Run these commands from the project root with the Docker services running and the inventory gRPC server ready.

If you need the first registered customer to receive ID `1` on an empty database, register them before seeding. Demo accounts use the same user ID sequence.

```powershell
docker compose exec app php artisan migrate
docker compose exec -e INVENTORY_GRPC_TIMEOUT=10000000 app php artisan db:seed
```

The extra gRPC time applies only to the seeding command. The seeders run in local and testing environments. They create 5 categories, 15 products with illustrated catalog images, and accounts, carts and orders connected to the inventory service.

New demo accounts share the password **StoreDemo2026!**. For the Postman admin login, set the email to **emily.carter@example.test**.

| Account | Scenario |
| --- | --- |
| emily.carter@example.test | Admin with access to the full order list and status changes |
| daniel.brooks@example.test | Pending order and one Sony WH-1000XM5 in the cart |
| olivia.bennett@example.test | Confirmed order and the same last available Sony WH-1000XM5 in the cart |
| sophie.miller@example.test | Processing order |
| james.turner@example.test | Shipped order |
| grace.wilson@example.test | Delivered order for the last Samsung Galaxy A55 |
| ethan.parker@example.test | Cancelled order with its stock released |
| mia.collins@example.test | Checkout that should return 409 because the cart includes the sold-out Samsung Galaxy A55 |
| noah.reed@example.test | Two available products ready for checkout |
| ava.morgan@example.test | Empty cart for the checkout validation test |

Send `GET /api/v1/products?per_page=5` to try pagination. Sign in as Daniel and Olivia to check order ownership or submit both carts concurrently to test the last piece. The catalog also has a Xiaomi Smart Band 8 with zero initial stock.

Run the same seed command again to complete any unfinished fixtures after the inventory service recovers. Existing demo account passwords and product stock are preserved, so checkout and cancellation tests use the quantities currently available.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
