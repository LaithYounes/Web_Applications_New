# Everyday Tech web application

The storefront, customer account, and admin console are served by Laravel. React and TypeScript use the existing Vite and Tailwind build. Laravel owns the catalog, stock, carts, orders, status history, and notification inbox.

## Run locally

1. Follow the [backend setup](../README.md#2-set-up-the-backend-for-the-first-time) to start Docker Compose, generate local keys, run migrations, and seed demo accounts and catalog products.
2. For a local demo, use `MAIL_MAILER=log` and `ORDER_PUSH_DRIVER=disabled` in `main-app/laravel/.env`. Recreate the application and workers if you changed their environment.
3. Use Node 22.12 or newer to install dependencies, check types, and build the web assets.

   ```powershell
   Set-Location main-app/laravel
   npm ci
   npm run typecheck
   npm run build
   Set-Location ../..
   ```

4. Check that the API reports `ok` and open the storefront.

   ```powershell
   (Invoke-RestMethod 'http://127.0.0.1:8081/api/v1/health').data.status
   Start-Process 'http://127.0.0.1:8081'
   ```

For hot reload, run `npm run dev -- --host 127.0.0.1 --strictPort` in `main-app/laravel` and keep using the Laravel page on port 8081. Stop the Vite server to use the compiled assets. If a terminated Vite process left a stale `public/hot` file, remove it before serving a production build.

Laravel reads the built files from `main-app/laravel/public/build`. In Compose this directory is visible through the source bind mount. For deployments without that bind mount, build the assets and include the directory in the Laravel release. The web shell supports direct refresh on the listed routes. The API, `/up`, storage, and compiled assets remain separate Laravel and static routes.

The demo catalog includes licensed product photos alongside the original illustrations. The storefront footer lists the photo credits and licenses. On an existing local demo database, run `docker compose exec -T -e INVENTORY_GRPC_TIMEOUT=10000000 app php artisan db:seed --class=DemoCatalogSeeder` to apply the photos to entries that still use their original demo illustration.

## Environment

Copy [the environment example](../main-app/laravel/.env.example) to `main-app/laravel/.env` for a new installation. Keep the real application key, JWT secret, and Reverb secret in your runtime environment.

| Setting | Local behavior |
| --- | --- |
| `APP_URL` | Use `http://127.0.0.1:8081` with the checked-in Compose file. Older clones may still use port 8000. |
| `STOREFRONT_BRAND` | Defaults to Everyday Tech. |
| `STOREFRONT_CURRENCY` | An optional ISO 4217 code such as `USD`. An empty value displays decimal amounts without a currency symbol. |
| `STOREFRONT_API_ORIGIN` | Defaults to the Laravel page origin. It can point public catalog reads to another origin serving `/api/v1`. That origin must allow the storefront through CORS and serve the same store data. |
| `STOREFRONT_STORAGE_ORIGIN` | Defaults to the Laravel page origin. Both catalog URLs and relative cart image paths resolve under `/storage/` on this origin. Run `php artisan storage:link` when setting up an image host. |
| `STOREFRONT_SESSION_DRIVER` | The Compose application defaults to the shared MySQL-backed `database` driver. It reads and writes sessions through the MySQL primary. Use the same `APP_KEY` on each replica. Outside Compose, configure Laravel's `SESSION_DRIVER` directly. |
| `SESSION_SAME_SITE` | `lax` by default. `strict` is also supported. Production always uses Secure and HttpOnly session cookies. Run the production storefront over HTTPS. |

Authentication and protected browser requests use the same-origin `/api/browser` routes. The adapter supplies the bearer JWT from the encrypted server session to the same Laravel API actions and authorization middleware. Sign-in and registration return the user to the browser. The Android and Postman clients continue using `/api/v1` with bearer tokens. The browser sends a CSRF token on mutations. Product uploads accept JPEG, PNG, and WebP up to 5 MiB. Keep the reverse proxy request limit above 5 MiB to allow multipart overhead.

### Reverb

Set `BROADCAST_CONNECTION=reverb` and configure matching `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` for Laravel and Reverb. Laravel publishes inside Compose to `REVERB_HOST=reverb`, `REVERB_PORT=8080`, and `REVERB_SCHEME=http` by default. The local browser connects to `ws://127.0.0.1:8080` when local Reverb is active. Set `STOREFRONT_REVERB_URL` to the browser-facing `wss://` origin in production and forward WebSocket upgrades there.

Reload the application and Reverb after changing these settings on an existing Compose installation.

```powershell
docker compose exec -T app php artisan config:clear
docker compose up -d --force-recreate app reverb
```

The same-origin adapter authorizes private subscriptions using the existing broadcast rules. Creation events refresh the admin order list and status events refresh the customer's order. The browser refreshes on focus and polls active order and inbox views when Reverb is unavailable. Browser notifications use the REST inbox.

## Demo

1. Open the storefront, choose a category, and open a product with stock. Select **Add to cart** as a guest. Register with an `example.test` email address or use `daniel.brooks@example.test` and the demo password `StoreDemo2026!`.
2. Add the product, update a cart quantity, and place the order from **Checkout**. Open **My orders** and **Notifications** to inspect order history and mark updates as read.
3. Sign out and use `emily.carter@example.test` with the demo password. In **Admin**, create a category and a product with an image and starting stock. Edit its details, replace the image, and delete the disposable listing.
4. Open the customer's order in **Admin → Orders** and move it through Confirmed, Processing, Shipped, and Delivered. **Order email failures** lets administrators filter and inspect recorded failures.
5. When checkout is awaiting inventory confirmation, use **Check orders** to see the latest state or **Retry checkout** to resume the same server attempt. See the backend notes for [checkout recovery](checkout-recovery.md), [status history](order-status-history.md), and [notifications](order-notifications.md).

## Checks

```powershell
docker compose exec -T app php artisan test --compact
```

Run order and inventory fault tests in a disposable Compose project with isolated databases and non-delivering notification configuration. The existing [notification integration environment](../docker-compose.notifications-test.yml) is a starting point.
