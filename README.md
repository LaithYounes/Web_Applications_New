# Backend Setup for the Android Team

Run the backend from PowerShell, then open your Android app in Android Studio. Your app uses the REST API on port `8000`. Docker runs Laravel, Spring Boot, and the databases.

## 1. Get the project

Accept the GitHub invitation and install Git, Docker Desktop, and Android Studio. Open Docker Desktop and wait for it to start. Then open PowerShell.

If this is your first time, run these commands from the folder where you want to download the project.

```powershell
git clone --branch main https://github.com/LaithYounes/Web_Applications_New.git
cd Web_Applications_New
docker compose version
```

If you already cloned the project, open PowerShell in the project folder and run `git pull --ff-only`. Run the backend commands below from the same folder, where `docker-compose.yml` is located.

## 2. Set up the backend for the first time

Run each step in order. The first build can take several minutes because Docker downloads the dependencies.

```powershell
$envFile = 'main-app/laravel/.env'
if (-not (Test-Path -LiteralPath $envFile)) {
    (Get-Content -LiteralPath "$envFile.example") -replace '^APP_URL=.*$', 'APP_URL=http://localhost:8000' |
        Set-Content -LiteralPath $envFile -Encoding ascii
}

docker compose build
```

Once the build finishes, run this block to create any missing local keys.

```powershell
if (-not (Select-String -Path $envFile -Pattern '^APP_KEY=.+$' -Quiet)) {
    docker compose run --rm --no-deps -T app php artisan key:generate
}
if (-not (Select-String -Path $envFile -Pattern '^JWT_SECRET=.+$' -Quiet)) {
    docker compose run --rm --no-deps -T app php artisan jwt:secret --always-no
}
```

Create the product image link and start the services.

```powershell
docker compose run --rm --no-deps -T app sh -ec 'test -d public/storage || ln -s ../storage/app/public public/storage'
docker compose up -d --wait
```

Watch the logs until MySQL and the inventory service are ready.

```powershell
docker compose logs -f --tail 50 mysql inventory-service
```

- For `mysql`, look for `ready for connections` on the line with `port: 3306`.
- For `inventory-service`, look for `gRPC Server started`.

Press `Ctrl+C` to stop watching the logs. Then create the database tables and add the demo data.

```powershell
docker compose exec -T app php artisan migrate --force
docker compose exec -T -e INVENTORY_GRPC_TIMEOUT=10000000 app php artisan db:seed
```

## 3. Check the API from PowerShell

```powershell
(Invoke-RestMethod 'http://localhost:8000/api/v1/health').data.status
(Invoke-RestMethod 'http://localhost:8000/api/v1/products?per_page=5').meta.total
```

The first command should return `ok`. On a fresh database with demo data, the second command returns `15`. You can now open your Android app in Android Studio.

Both local demo accounts use the password `StoreDemo2026!`.

| Account | Use |
| --- | --- |
| `noah.reed@example.test` | Customer with items in the cart for testing checkout |
| `emily.carter@example.test` | Admin for testing product and order management |

## 4. Connect the Android app

1. Open your **Android app project** in Android Studio, sync Gradle, and start an emulator from **Device Manager**.
2. In the emulator's browser, open `http://10.0.2.2:8000/api/v1/health`. Look for `ok` in `data.status`. The emulator uses `10.0.2.2` to reach your computer. [Android emulator networking](https://developer.android.com/studio/run/emulator-networking-address)
3. Set the debug **base URL** to `http://10.0.2.2:8000/api/v1/`. Start with `GET products?per_page=5` and display the items in `data`.
4. Make sure your app's `AndroidManifest.xml` includes `<uses-permission android:name="android.permission.INTERNET" />` inside `<manifest>`. If you see `CLEARTEXT communication not permitted`, use the debug settings below. [Android network permissions](https://developer.android.com/develop/connectivity/network-ops/connecting)

To test on a physical phone, enable **USB debugging**, connect the phone, and run `adb reverse tcp:8000 tcp:8000` in the terminal. Use `http://localhost:8000/api/v1/` as the base URL on the phone. If Android blocks HTTP, allow `localhost` in the debug build. [ADB reverse setup](https://developer.android.com/develop/ui/views/layout/webapps/access-local-server)

<details>
<summary>Allow local HTTP in the debug build</summary>

If your debug build already has a network configuration file, add the local addresses to it. If the main manifest already points to a network configuration file, copy that file into `app/src/debug/res/xml` with the same name and add the `domain-config` below. If your app has no network configuration yet, save this file as `app/src/debug/res/xml/network_security_config_local.xml`.

```xml
<network-security-config>
    <domain-config cleartextTrafficPermitted="true">
        <domain>10.0.2.2</domain>
        <domain>localhost</domain>
    </domain-config>
</network-security-config>
```

If you created a new configuration file, link it in `app/src/debug/AndroidManifest.xml`. If the debug manifest already exists, add the `android:networkSecurityConfig` attribute to its `<application>` element.

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:networkSecurityConfig="@xml/network_security_config_local" />
</manifest>
```

Build and run the debug app again. [Android network security configuration](https://developer.android.com/privacy-and-security/security-config)

</details>

### First API requests

Add the paths below to the base URL. Send `Accept: application/json` with each request. For requests with a JSON body, also send `Content-Type: application/json`.

| What to build | Request | What to send or read |
| --- | --- | --- |
| Categories and product list | `GET categories`, `GET products?page=1&per_page=12` | Read the items in `data` and the page information in `meta`. |
| Product details | `GET products/{id}` | Read available stock from `data.available_quantity`. |
| Sign-in | `POST auth/login` | Send `email` and `password`. Save `data.token` for the session and use `data.user.role` to show the customer or admin screens. |
| View the cart | `GET cart` | Read the items from `data.items` and the total from `data.total_amount`. |
| Add a cart item | `POST cart/items` | Send `{"product_id": 1, "quantity": 1}`. Use the product ID from the catalog. |
| Change or remove a cart item | `PUT cart/items/{itemId}` or `DELETE cart/items/{itemId}` | Use the cart item ID from `data.items[].id`. Send `quantity` when changing it. |
| Checkout | `POST orders` | Send an empty body. The response contains the new order and its ID in `data.id`. |
| View orders | `GET orders` or `GET orders/{id}` | Use the order ID from checkout or the order list. |
| Cancel an order | `POST orders/{id}/cancel` | Send an empty body with a customer token. The customer can cancel their own pending order. On `200`, read the cancelled order from `data`. |

After signing in, send `Authorization: Bearer <token>` with cart, order, and session requests. If a protected request returns `401`, sign in again. The [frontend Postman collection](Ecommerce.Frontend.postman_collection.json) has examples for registration, logout, and admin requests.

Show the cancellation action on pending orders. If cancellation is interrupted, refresh order details and retry the same request. Order details show `cancellation_pending: true` while the inventory release is awaiting confirmation. Once the order is cancelled, a repeat returns `200` with status `cancelled`.

**Edit and delete products as an admin**

| Action | Request | Body and response |
| --- | --- | --- |
| Edit product details | `PATCH products/{id}` | Send only the fields to change as JSON. Supported fields are `category_id`, `name`, `description`, and `price`. Send `description: null` to clear it. Returns `200` with the updated product in `data`. |
| Replace the product image | `POST products/{id}` | Send multipart form data with `_method=PATCH` and an `image` file (JPEG, PNG, or WebP up to 5 MiB). Include any other fields to edit in the same request. Returns `200` with the updated product in `data`. |
| Delete a product | `DELETE products/{id}` | Returns `200` with `data: null`. The product disappears from the catalog and carts. Existing orders and their reservations are preserved. |

Use an admin bearer token for each request. Deleted and unpublished products return `404`. Field validation errors return `422`. Read available stock through `GET products/{id}`.

**Product images in the emulator**

A catalog image URL can start with `http://localhost:8000`. In the debug app, replace that part with `http://10.0.2.2:8000` and keep the rest of the URL. A cart item's image comes as a path such as `products/example.png`. Add `http://10.0.2.2:8000/storage/` before that path. The API returns prices and totals as strings such as `"25.00"`.

## After the first run

- **Start the backend** from the project folder with `docker compose up -d --wait`.
- **Get backend updates** using the [ordered upgrade steps](docs/checkout-recovery.md#upgrading-an-existing-local-checkout). This release changes Laravel, inventory, database tables, and the recovery service: stop the application before pulling, rebuild, start inventory, apply migrations, then start the application and recovery service.
- **If `main-app/laravel/composer.lock` changed**, refresh the shared dependency volume with `docker compose run --rm --no-deps -T app composer install --no-interaction --prefer-dist` before running migrations in those upgrade steps.
- **Stop the backend** with `docker compose stop`. Your demo data stays saved.
- **If the API does not respond**, run `docker compose ps` and `docker compose logs --tail 50 app inventory-service`. If the API works from PowerShell, open `/api/v1/health` in the emulator's browser before checking the app code.
- **If checkout returns `503`**, check the order list and cart before trying again. If it keeps happening, share the error and the output of `docker compose logs --tail 50 inventory-service` with the backend team.

Checkout recovery runs automatically in the `checkout-recovery` Compose service. After an unconfirmed inventory response, `GET cart` shows `checkout_pending: true`; cart changes return `409` until that checkout settles. Retrying `POST orders` resumes the same reservation and basket. The recovery service also resumes attempts at least 60 seconds old every minute, so an order may appear after a `503` without a customer retry. See [checkout recovery](docs/checkout-recovery.md) for the transaction design, verification, and rollout order.

Order details (`GET orders/{id}`) include `data.status_history`, ordered from oldest to newest. Each entry contains `from_status`, `to_status`, `changed_by` (user ID or null), `actor_type` (`customer`, `admin`, or `system`), and `changed_at` in UTC. New orders record their initial status, and subsequent changes are recorded atomically with the order update. History starts when this feature is installed; earlier transitions cannot be reconstructed. The frontend collection's existing **Get my order details** request displays it without any collection changes. See [order status history](docs/order-status-history.md).
