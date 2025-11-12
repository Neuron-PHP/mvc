[![CI](https://github.com/Neuron-PHP/mvc/actions/workflows/ci.yml/badge.svg)](https://github.com/Neuron-PHP/mvc/actions)
# Neuron-PHP MVC

A lightweight MVC (Model-View-Controller) framework component for PHP 8.4+ that provides core MVC functionality including controllers, views, routing integration, request handling, and a powerful view caching system.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Core Components](#core-components)
- [Configuration](#configuration)
- [Usage Examples](#usage-examples)
- [Advanced Features](#advanced-features)
- [CLI Commands](#cli-commands)
- [API Reference](#api-reference)
- [Testing](#testing)
- [More Information](#more-information)

## Installation

### Requirements

- PHP 8.4 or higher
- Composer

### Install via Composer

Install php composer from https://getcomposer.org/

Install the neuron MVC component:

```bash
composer require neuron-php/mvc
```

## Quick Start

### 1. Create the Front Controller

Create a `public/index.php` file:

```php
<?php
require_once '../vendor/autoload.php';

// Bootstrap the application
$app = boot('../config');

// Dispatch the current request
dispatch($app);
```

### 2. Configure Apache (.htaccess)

Create a `public/.htaccess` file to route all requests through the front controller:

```apache
IndexIgnore *

Options +FollowSymlinks
RewriteEngine on

# Redirect all requests to index.php
# except for actual files and directories
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f

RewriteRule ^(.*)$ index.php?route=$1 [L,QSA]
```

#### For Nginx

If using Nginx, add this to your server configuration:

```nginx
location / {
    try_files $uri $uri/ /index.php?route=$uri&$args;
}
```

### 3. Minimal Configuration

Create a `config/neuron.yaml` file:

```yaml
system:
  base_path: .
  routes_path: config

views:
  path: resources/views
```

Create a `config/routes.yaml` file:

```yaml
routes:
  home:
    route: /
    method: GET
    controller: App\Controllers\HomeController@index
```


## Core Components

### Application

The main application class (`Neuron\Mvc\Application`) handles:
- Route configuration loading from YAML files
- Request routing and controller execution
- Event dispatching for HTTP errors
- Output capture for testing
- Cache management

### Controllers

Controllers handle incoming requests and return responses. All controllers should extend `Neuron\Mvc\Controllers\Base` and implement the `IController` interface.

```php
namespace App\Controllers;

use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Responses\HttpResponseStatus;

class HomeController extends Base
{
    public function index(): string
    {
        return $this->renderHtml(
            HttpResponseStatus::OK,
            ['title' => 'Welcome'],
            'index',  // view file
            'default' // layout file
        );
    }
}
```

Available render methods:
- `renderHtml()` - Render HTML views with layouts
- `renderJson()` - Return JSON responses
- `renderXml()` - Return XML responses
- `renderMarkdown()` - Render Markdown content with CommonMark

### Views

Views support multiple formats and are stored in the configured views directory:

#### HTML Views
```php
// resources/views/home/index.php
<h1><?php echo $title; ?></h1>
```

#### Layouts
```php
// resources/views/layouts/default.php
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $title ?? 'My App'; ?></title>
</head>
<body>
    <?php echo $Content; ?>
</body>
</html>
```

### Routing

Routes are defined in YAML files and support various HTTP methods:

```yaml
routes:
  user_profile:
    route: /user/{id}
    method: GET
    controller: App\Controllers\UserController@profile
    request: user_profile  # optional request validation
    
  api_users:
    route: /api/users
    method: POST
    controller: App\Controllers\Api\UserController@create
```

### Request Handling

Create request parameter definitions for validation:

```yaml
# config/requests/user_profile.yaml
parameters:
  id:
    type: integer
    required: true
    min_value: 1
```

Access parameters in controllers:

```php
public function profile(Request $request): string
{
    $userId = $request->getParam('id')->getValue();
    // ...
}
```

### URL Helpers

The framework provides Rails-style URL helpers for generating URLs from named routes. This makes it easy to generate consistent URLs throughout your application.

#### Route Naming

Routes are automatically named based on their configuration key in the YAML file:

```yaml
routes:
  user_profile:  # This becomes the route name
    route: /users/{id}
    method: GET
    controller: App\Controllers\UserController@profile
    
  admin_user_posts:
    route: /admin/users/{user_id}/posts/{post_id}
    method: GET
    controller: App\Controllers\AdminController@userPosts
```

#### Using URL Helpers in Controllers

Controllers can use URL helpers directly via magic methods:

```php
class UserController extends Base
{
    public function show($id): string
    {
        $user = User::find($id);
        
        // Magic methods for URL generation
        $editUrl = $this->userEditPath(['id' => $id]);
        $absoluteUrl = $this->userProfileUrl(['id' => $id]);
        
        // Use in redirects
        if (!$user) {
            return redirect($this->userIndexPath());
        }
        
        return $this->renderHtml(HttpResponseStatus::OK, [
            'user' => $user,
            'edit_url' => $editUrl
        ]);
    }
    
    public function create(): string
    {
        // After creating user, redirect using magic method
        $user = new User($request->all());
        $user->save();
        
        return redirect($this->userProfilePath(['id' => $user->id]));
    }
}
```

#### Direct URL Helper Methods

Controllers also provide direct helper methods:

```php
// Generate relative URLs
$profileUrl = $this->urlFor('user_profile', ['id' => 123]);

// Generate absolute URLs  
$absoluteUrl = $this->urlForAbsolute('user_profile', ['id' => 123]);

// Check if route exists
if ($this->routeExists('user_profile')) {
    // Route is available
}

// Get UrlHelper instance for advanced usage
$urlHelper = $this->urlHelper();
```

#### Using URL Helpers in Views

URL helpers are automatically available in all views through the injected `$urlHelper` variable:

```php
<!-- resources/views/user/profile.php -->
<div class="user-profile">
    <h1><?= $user->name ?></h1>
    
    <!-- Magic methods in views -->
    <a href="<?= $urlHelper->userEditPath(['id' => $user->id]) ?>" class="btn">Edit</a>
    <a href="<?= $urlHelper->userPostsPath(['user_id' => $user->id]) ?>" class="btn">View Posts</a>
    
    <!-- Complex routes work too -->
    <a href="<?= $urlHelper->adminUserReportsPath(['id' => $user->id, 'year' => date('Y')]) ?>">
        Admin Reports
    </a>
    
    <!-- Direct method calls -->
    <a href="<?= $urlHelper->routePath('user_profile', ['id' => $user->id]) ?>">Profile</a>
    <a href="<?= $urlHelper->routeUrl('user_profile', ['id' => $user->id]) ?>">Share Link</a>
</div>
```

#### Magic Method Conventions

The magic methods follow Rails naming conventions:

| Route Name in YAML | Magic Method (Relative) | Magic Method (Absolute) | Generated URL |
|---------------------|------------------------|-------------------------|---------------|
| `user_profile` | `userProfilePath()` | `userProfileUrl()` | `/users/123` |
| `user_edit` | `userEditPath()` | `userEditUrl()` | `/users/123/edit` |
| `admin_user_posts` | `adminUserPostsPath()` | `adminUserPostsUrl()` | `/admin/users/1/posts/2` |
| `blog_category` | `blogCategoryPath()` | `blogCategoryUrl()` | `/blog/category/tech` |

#### URL Helper Methods

| Method | Description | Example |
|--------|-------------|---------|
| `routePath($name, $params)` | Generate relative URL | `$urlHelper->routePath('user_profile', ['id' => 123])` |
| `routeUrl($name, $params)` | Generate absolute URL | `$urlHelper->routeUrl('user_profile', ['id' => 123])` |
| `routeExists($name)` | Check if route exists | `$urlHelper->routeExists('user_profile')` |
| `getAvailableRoutes()` | List all named routes | `$urlHelper->getAvailableRoutes()` |
| `{routeName}Path($params)` | Magic method for relative URL | `$urlHelper->userProfilePath(['id' => 123])` |
| `{routeName}Url($params)` | Magic method for absolute URL | `$urlHelper->userProfileUrl(['id' => 123])` |

#### Error Handling

URL helpers gracefully handle missing routes:

```php
// Returns null if route doesn't exist
$url = $urlHelper->nonExistentRoutePath(['id' => 123]);

if ($url === null) {
    // Handle missing route
    $url = $urlHelper->userIndexPath(); // fallback
}
```

#### Advanced Usage

```php
// Get all available routes for debugging
$routes = $urlHelper->getAvailableRoutes();
foreach ($routes as $route) {
    echo "Route: {$route['name']} -> {$route['method']} {$route['path']}\n";
}

// Custom UrlHelper instance
$customHelper = new UrlHelper($customRouter);
```

## Configuration

All YAML config file parameters can be overridden by environment variables in the form of `<CATEGORY>_<KEY>`, e.g.
`SYSTEM_BASE_PATH`.

### Main Configuration (neuron.yaml)

```yaml
# System settings
system:
  timezone: US/Eastern
  base_path: .
  routes_path: config

# View settings
views:
  path: resources/views

# Logging
logging:
  destination: \Neuron\Log\Destination\File
  format: \Neuron\Log\Format\PlainText
  file: app.log
  level: debug

# Cache configuration
cache:
  enabled: true
  storage: file
  path: cache/views
  ttl: 3600  # Default TTL in seconds
  html: true      # Enable HTML view caching
  markdown: true  # Enable Markdown view caching
  json: false     # Disable JSON response caching
  xml: false      # Disable XML response caching
  # Garbage collection settings (optional)
  gc_probability: 0.01  # 1% chance to run GC on cache write
  gc_divisor: 100      # Fine-tune probability calculation
```

### Cache Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `enabled` | Enable/disable caching globally | `true` |
| `storage` | Storage type (currently only 'file') | `file` |
| `path` | Directory for cache files | `cache/views` |
| `ttl` | Default time-to-live in seconds | `3600` |
| `views.*` | Enable caching per view type | varies |
| `gc_probability` | Probability of running garbage collection | `0.01` |
| `gc_divisor` | Divisor for probability calculation | `100` |

## Usage Examples

### Creating a Controller

```php
namespace App\Controllers;

use Neuron\Mvc\Controllers\Base;
use Neuron\Mvc\Requests\Request;
use Neuron\Mvc\Responses\HttpResponseStatus;

class ProductController extends Base
{
    public function list(): string
    {
        $products = $this->getProducts();
        
        return $this->renderHtml(
            HttpResponseStatus::OK,
            ['products' => $products],
            'list',
            'default'
        );
    }
    
    public function apiList(): string
    {
        $products = $this->getProducts();
        
        return $this->renderJson(
            HttpResponseStatus::OK,
            ['products' => $products]
        );
    }
    
    public function details(Request $request): string
    {
        $id = $request->getParam('id')->getValue();
        $product = $this->getProduct($id);
        
        if (!$product) {
            return $this->renderHtml(
                HttpResponseStatus::NOT_FOUND,
                ['message' => 'Product not found'],
                'error',
                'default'
            );
        }
        
        return $this->renderHtml(
            HttpResponseStatus::OK,
            ['product' => $product],
            'details',
            'default'
        );
    }
}
```

### Request Parameter Validation

Define parameters in YAML:

```yaml
# config/requests/product_create.yaml
parameters:
  name:
    type: string
    required: true
    min_length: 3
    max_length: 100
    
  price:
    type: currency
    required: true
    min_value: 0
    
  category_id:
    type: integer
    required: true
    
  description:
    type: string
    required: false
    max_length: 1000
```

Available parameter types:
- `string`, `integer`, `float`, `boolean`
- `email`, `url`, `uuid`
- `date`, `datetime`
- `currency`, `phone_number`
- `array`, `object`

### Error Handling

The framework automatically handles 404 errors:

```php
// Custom 404 handler
class NotFoundController extends HttpCodes
{
    public function render404(): string
    {
        return $this->renderHtml(
            HttpResponseStatus::NOT_FOUND,
            ['message' => 'Page not found'],
            '404',
            'error'
        );
    }
}
```

## Advanced Features

### View Caching

The framework includes a sophisticated view caching system with multiple storage backends:

#### Storage Backends

1. **File Storage** (Default): Uses the local filesystem for cache storage
2. **Redis Storage**: High-performance in-memory caching with Redis

#### Features

1. **Automatic Cache Key Generation**: Based on controller, view, and data
2. **Selective Caching**: Enable/disable per view type
3. **TTL Support**: Configure expiration times
4. **Garbage Collection**: Automatic cleanup of expired entries
5. **Multiple Storage Backends**: Choose between file or Redis storage

#### Configuration

##### File Storage Configuration
```yaml
cache:
  enabled: true
  storage: file
  path: cache/views
  ttl: 3600
  views:
    html: true
    markdown: true
    json: false
    xml: false
```

##### Redis Storage Configuration
```yaml
cache:
  enabled: true
  storage: redis          # Use Redis instead of file storage
  ttl: 3600
  # Redis configuration (flat structure for env variable compatibility)
  redis_host: 127.0.0.1
  redis_port: 6379
  redis_database: 0
  redis_prefix: neuron_cache_
  redis_timeout: 2.0
  redis_auth: null       # Optional: Redis password
  redis_persistent: false # Optional: Use persistent connections
  # View-specific cache settings
  html: true
  markdown: true
  json: false
  xml: false
```

This flat structure ensures compatibility with environment variables:
- `CACHE_STORAGE=redis`
- `CACHE_REDIS_HOST=127.0.0.1`
- `CACHE_REDIS_PORT=6379`
- etc.

#### Programmatic Usage

```php
// Cache is automatically used when enabled
$html = $this->renderHtml(
    HttpResponseStatus::OK,
    $data,
    'cached-view',
    'layout'
);
```

#### Manual Cache Management

```php
// Clear all expired cache entries (file storage only)
$removed = ClearExpiredCache($app);
echo "Removed $removed expired cache entries";

// Clear all cache
$app->getViewCache()->clear();
```

#### Using CacheStorageFactory

```php
use Neuron\Mvc\Cache\Storage\CacheStorageFactory;

// Create storage based on configuration
$storage = CacheStorageFactory::create([
    'storage' => 'redis',
    'redis_host' => 'localhost',
    'redis_port' => 6379,
    'redis_database' => 0,
    'redis_prefix' => 'neuron_cache_'
]);

// Auto-detect best available storage
$storage = CacheStorageFactory::createAutoDetect();

// Check storage availability
if (CacheStorageFactory::isAvailable('redis')) {
    echo "Redis cache is available";
}
```

You can also manage cache using the CLI commands. See [CLI Commands](#cli-commands) section for details.

### Custom View Implementations

Create custom view types by implementing `IView`:

```php
namespace App\Views;

use Neuron\Mvc\Views\IView;

class PdfView implements IView
{
    public function render(array $Data): string
    {
        // Generate PDF content
        return $pdfContent;
    }
}
```

### Event System

Listen for HTTP events:

```yaml
# config/events.yaml
listeners:
  http_404:
    class: App\Listeners\NotFoundLogger
    method: logNotFound
```

## CLI Commands

The MVC component includes several CLI commands for managing cache and routes. These commands are available when using the Neuron CLI tool.

### Cache Management Commands

#### mvc:cache:clear

Clear view cache entries.

**Options:**
- `--type, -t VALUE` - Clear specific cache type (html, json, xml, markdown)
- `--expired, -e` - Only clear expired entries
- `--force, -f` - Clear without confirmation
- `--config, -c PATH` - Path to configuration directory

**Examples:**

```bash
# Clear all cache entries (with confirmation)
neuron mvc:cache:clear

# Clear only expired entries
neuron mvc:cache:clear --expired

# Clear specific cache type
neuron mvc:cache:clear --type=html

# Force clear without confirmation
neuron mvc:cache:clear --force

# Specify custom config path
neuron mvc:cache:clear --config=/path/to/config
```

#### mvc:cache:stats

Display comprehensive cache statistics.

**Options:**
- `--config, -c PATH` - Path to configuration directory
- `--json, -j` - Output statistics in JSON format
- `--detailed, -d` - Show detailed breakdown by view type

**Examples:**

```bash
# Display cache statistics
neuron mvc:cache:stats

# Show detailed statistics with view type breakdown
neuron mvc:cache:stats --detailed

# Output as JSON for scripting
neuron mvc:cache:stats --json

# Detailed JSON output
neuron mvc:cache:stats --detailed --json
```

**Sample Output:**

```
MVC View Cache Statistics
==================================================
Configuration:
Cache Path: /path/to/cache/views
Cache Enabled: Yes
Default TTL: 3600 seconds (1 hour)

Overall Statistics:
Total Cache Entries: 247
Valid Entries: 189
Expired Entries: 58
Total Cache Size: 2.4 MB
Average Entry Size: 10.2 KB
Oldest Entry: 2025-08-10 14:23:15
Newest Entry: 2025-08-13 09:45:32

Recommendations:
- 58 expired entries can be cleared (saving ~580 KB)
  Run: neuron mvc:cache:clear --expired
```

## Rate Limiting

The MVC component includes integrated rate limiting support through the routing component. Rate limiting helps protect your application from abuse and ensures fair resource usage.

### Configuration

Rate limiting is configured in your `neuron.yaml` file using two categories:

#### Standard Rate Limiting
```yaml
rate_limit:
  enabled: false          # Enable/disable rate limiting
  global: false          # Apply to all routes globally
  storage: file          # Storage backend: file, redis, memory (testing only)
  requests: 100          # Maximum requests per window
  window: 3600           # Time window in seconds (1 hour)
  file_path: cache/rate_limits
  # Redis configuration (if storage: redis)
  # redis_host: 127.0.0.1
  # redis_port: 6379
```

#### API Rate Limiting (Higher Limits)
```yaml
api_limit:
  enabled: false
  storage: file
  requests: 1000         # 1000 requests per hour
  window: 3600
  file_path: cache/api_limits
```

### Environment Variables

Configuration maps to environment variables using the `{category}_{name}` pattern:
- `RATE_LIMIT_ENABLED=true`
- `RATE_LIMIT_STORAGE=redis`
- `RATE_LIMIT_REQUESTS=100`
- `API_LIMIT_ENABLED=true`
- `API_LIMIT_REQUESTS=1000`

### Usage in Routes

#### Global Application
Set `global: true` in configuration to apply rate limiting to all routes:
```yaml
rate_limit:
  enabled: true
  global: true
  requests: 100
  window: 3600
```

#### Per-Route Application
Apply rate limiting to specific routes using the `filter` parameter in `routes.yaml`:

```yaml
routes:
  # Public page - no rate limiting
  - name: home
    method: GET
    route: /
    controller: HomeController@index

  # Standard protected route with rate limiting
  - name: user_profile
    method: GET
    route: /user/profile
    controller: UserController@profile
    filter: rate_limit      # Apply rate_limit (100/hour)

  # API endpoint with higher limits
  - name: api_users
    method: GET
    route: /api/users
    controller: ApiController@users
    filter: api_limit       # Apply api_limit (1000/hour)
```

### Storage Backends

#### File Storage (Default)
Best for single-server deployments:
```yaml
rate_limit:
  storage: file
  file_path: cache/rate_limits  # Directory for rate limit files
```

#### Redis Storage (Recommended for Production)
Best for distributed systems and high traffic:
```yaml
rate_limit:
  storage: redis
  redis_host: 127.0.0.1
  redis_port: 6379
  redis_database: 0
  redis_prefix: rate_limit_
  redis_auth: password     # Optional
  redis_persistent: true   # Use persistent connections
```

#### Memory Storage (Testing Only)
For unit tests and development. Data is lost when PHP process ends:
```yaml
rate_limit:
  storage: memory
```

### Rate Limit Headers

When rate limiting is active, the following headers are included in responses:
- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Requests remaining in current window
- `X-RateLimit-Reset`: Unix timestamp when limit resets

When limit is exceeded (HTTP 429):
- `Retry-After`: Seconds until retry is allowed

### Example Implementation

1. Enable rate limiting in `neuron.yaml`:
```yaml
rate_limit:
  enabled: true
  global: false
  storage: redis
  requests: 100
  window: 3600
  redis_host: 127.0.0.1

api_limit:
  enabled: true
  storage: redis
  requests: 1000
  window: 3600
  redis_host: 127.0.0.1
```

2. Apply to routes in `routes.yaml`:
```yaml
routes:
  - name: login
    method: POST
    route: /auth/login
    controller: AuthController@login
    filter: rate_limit    # Strict limit for login attempts

  - name: api_data
    method: GET
    route: /api/data
    controller: ApiController@getData
    filter: api_limit     # Higher limit for API access
```

### Customization

For advanced use cases, you can extend the rate limiting system by creating custom filters in your application. The rate limiting system automatically detects if the routing component version supports it and gracefully degrades if not available.

### Route Management Commands

#### mvc:routes:list

List all registered routes with filtering options.

**Options:**
- `--config, -c PATH` - Path to configuration directory
- `--controller VALUE` - Filter by controller name
- `--method, -m VALUE` - Filter by HTTP method (GET, POST, PUT, DELETE, etc.)
- `--pattern, -p VALUE` - Search routes by pattern
- `--json, -j` - Output routes in JSON format

**Examples:**

```bash
# List all routes
neuron mvc:routes:list

# Filter by controller
neuron mvc:routes:list --controller=UserController

# Filter by HTTP method
neuron mvc:routes:list --method=POST

# Search by pattern
neuron mvc:routes:list --pattern=/api/

# Combine filters
neuron mvc:routes:list --controller=Api --method=GET

# Output as JSON for processing
neuron mvc:routes:list --json
```

**Sample Output:**

```
MVC Routes
======================================================================================
Name                    | Pattern              | Method | Controller         | Action
--------------------------------------------------------------------------------------
home                    | /                    | GET    | HomeController     | index
user_profile            | /user/{id}           | GET    | UserController     | profile
api_users_list          | /api/users           | GET    | Api\UserController | list
api_users_create        | /api/users           | POST   | Api\UserController | create
products_list           | /products            | GET    | ProductController  | list
product_details         | /products/{id}       | GET    | ProductController  | details

Total routes: 6
Named routes: 6
Methods: GET: 4, POST: 2
```

## API Reference

### Bootstrap Functions

#### Boot(string $ConfigPath): Application
Initialize the application with configuration.

```php
$app = Boot('/path/to/config');
```

#### Dispatch(Application $App): void
Process the current HTTP request.

```php
Dispatch($app);
```

#### ClearExpiredCache(Application $App): int
Remove expired cache entries.

```php
$removed = ClearExpiredCache($app);
```

### Key Interfaces

#### IController
All controllers must implement this interface:
- `renderHtml()` - Render HTML with layout
- `renderJson()` - Render JSON response
- `renderXml()` - Render XML response

#### IView
Views must implement:
- `render(array $Data): string` - Render the view

#### ICacheStorage
Cache storage implementations must provide:
- `read()`, `write()`, `exists()`, `delete()`
- `clear()` - Clear all entries
- `isExpired()` - Check expiration
- `gc()` - Garbage collection

## Testing

Run the test suite:

```bash
# Run all tests
vendor/bin/phpunit -c tests/phpunit.xml

# Run with coverage
vendor/bin/phpunit -c tests/phpunit.xml --coverage-html coverage

# Run specific test file
vendor/bin/phpunit -c tests/phpunit.xml tests/Mvc/ApplicationTest.php
```

## More Information

You can read more about the Neuron components at [neuronphp.com](http://neuronphp.com)
