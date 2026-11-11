-- Baseline EXPLAIN pack. Run only on ecommerce_main_perf AFTER data generation.
-- Shapes follow the Eloquent queries; they are not an optimized rewrite.
-- Do not CREATE INDEX from this file.

SELECT DATABASE() AS live_database;

-- k6 GET /api/v1/products?per_page=20
-- ProductService::getAll(null, 20): published + latest() + orderByDesc(id) + paginate
EXPLAIN SELECT COUNT(*) AS aggregate
FROM products
WHERE pending_initial_quantity IS NULL;

EXPLAIN SELECT *
FROM products
WHERE pending_initial_quantity IS NULL
ORDER BY created_at DESC, id DESC
LIMIT 20 OFFSET 0;

-- Eager category ids for that page (with('category'))
EXPLAIN SELECT *
FROM categories
WHERE id IN (
  SELECT category_id FROM (
    SELECT category_id
    FROM products
    WHERE pending_initial_quantity IS NULL
    ORDER BY created_at DESC, id DESC
    LIMIT 20
  ) AS page_categories
);

-- Same listing filtered by category (supported by the API, not the default k6 script)
SET @category_id := (
  SELECT category_id
  FROM products
  WHERE pending_initial_quantity IS NULL
  GROUP BY category_id
  ORDER BY COUNT(*) DESC
  LIMIT 1
);

EXPLAIN SELECT COUNT(*) AS aggregate
FROM products
WHERE pending_initial_quantity IS NULL
  AND category_id = @category_id;

EXPLAIN SELECT *
FROM products
WHERE pending_initial_quantity IS NULL
  AND category_id = @category_id
ORDER BY created_at DESC, id DESC
LIMIT 20 OFFSET 0;

-- k6 GET /api/v1/products/{id}
-- Product::published()->with('category')->findOrFail(id) then one GetStock RPC (not SQL)
SET @product_id := (
  SELECT id FROM products WHERE pending_initial_quantity IS NULL ORDER BY id LIMIT 1
);

EXPLAIN SELECT *
FROM products
WHERE pending_initial_quantity IS NULL
  AND id = @product_id
LIMIT 1;

-- k6 GET /api/v1/orders as perf.user.00001@example.test (customer)
-- OrderService::getUserOrders: where user_id + latest() + paginate(10) + with('items')
SET @user_id := (
  SELECT id FROM users WHERE email = 'perf.user.00001@example.test' LIMIT 1
);

EXPLAIN SELECT COUNT(*) AS aggregate
FROM orders
WHERE user_id = @user_id;

EXPLAIN SELECT *
FROM orders
WHERE user_id = @user_id
ORDER BY created_at DESC
LIMIT 10 OFFSET 0;

-- Eloquent whereIn(order_id) for the page. Equality shows the order_id index probe.
EXPLAIN SELECT *
FROM order_items
WHERE order_id = (
  SELECT id FROM orders WHERE user_id = @user_id ORDER BY created_at DESC LIMIT 1
);

-- Admin only: OrderService::getAllOrders (do not use this token in the customer k6 script)
EXPLAIN SELECT COUNT(*) AS aggregate FROM orders;

EXPLAIN SELECT *
FROM orders
ORDER BY created_at DESC
LIMIT 15 OFFSET 0;

-- GET /api/v1/categories — CategoryService::getAll withCount('products')
EXPLAIN SELECT categories.*, (
  SELECT COUNT(*)
  FROM products
  WHERE products.category_id = categories.id
) AS products_count
FROM categories
ORDER BY name ASC
LIMIT 15 OFFSET 0;

SHOW INDEX FROM products;
SHOW INDEX FROM orders;
SHOW INDEX FROM order_items;
SHOW INDEX FROM categories;
