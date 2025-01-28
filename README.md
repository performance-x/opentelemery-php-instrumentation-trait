# OpenTelemetry PHP Instrumentation Trait
A trait to simplify creating OpenTelemetry instrumentations for PHP classes, interfaces, and functions.

## Installation
```bash
composer require performance-x/opentelemetry-php-instrumentation-trait
```

## Usage
The trait is designed to be used in instrumentation classes that register OpenTelemetry hooks for specific targets.

```php
<?php
namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Cache\CacheBackendInterface;
use OpenTelemetry\API\Trace\SpanKind;
use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

class CacheBackendInstrumentation {
  use InstrumentationTrait;

  public static function register(): void {
    // Create instance with name and prefix
    $instrumentation = self::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal.cache',
      className: CacheBackendInterface::class
    );

    // Or with custom span kind
    $instrumentation = self::create(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal.cache',
      spanKind: SpanKind::KIND_CLIENT,
      className: CacheBackendInterface::class
    );

    // Or with pre-configured instrumentation
    $instrumentation = self::create(
      instrumentation: new CachedInstrumentation('custom.name'),
      prefix: 'drupal.cache',
      className: CacheBackendInterface::class
    );

    // Register method hooks
    $instrumentation
      ->helperHook(
        'get',
        ['cid'],
        'returnValue'
      )
      ->helperHook(
        'set',
        ['cid', 'data', 'expire', 'tags'],
        'returnValue',
        preHandler: function($spanBuilder, $object, array $params, $class, $function, $filename, $lineno) {
          $spanBuilder->setAttribute($this->getAttributeName('ttl'), $params[2] ?? 0);
        },
        postHandler: function($span, $object, array $params, $returnValue, $exception) {
          $span->setAttribute($this->getAttributeName('success'), $returnValue !== FALSE);
        }
      );
  }
}
```

## Features
- Easy initialization of OpenTelemetry instrumentation
- Support for both class methods and standalone functions
- Configurable span kind (defaults to INTERNAL)
- Automatic prefix for all span attributes via getAttributeName()
- Parameter mapping to span attributes
- Support for pre and post handlers with span access
- Automatic exception handling
- Return value capturing
- Code location attributes (function, namespace, file, line)

## Configuration Options
The `create()` method accepts:
- `instrumentation`: Optional pre-configured instrumentation instance
- `prefix`: Optional prefix for all span attributes
- `spanKind`: Kind of spans to create (default: INTERNAL)
- `className`: Optional target class name
- `name`: Name of the instrumentation if no instrumentation instance provided

At least one of `instrumentation` or `name` must be provided.

## Hook Configuration
The `helperHook()` method accepts:
- `methodName`: The method or function to hook
- `paramMap`: Array of parameters to capture as attributes
- `returnValueKey`: Optional key for the return value attribute
- `preHandler`: Optional callback for custom span building
- `postHandler`: Optional callback for custom span finishing
- `className`: Optional override of target class name

## Parameter Mapping
The `paramMap` argument in `helperHook()` supports several ways to map method parameters to span attributes:

### Value Handling
The trait automatically handles different parameter types appropriately. For example, with Drupal's cache `set` method:

```php
/**
* @see \Drupal\Core\Cache\CacheBackendInterface::set()
*/
$instrumentation->helperHook(
    'set',
    [
        'cid',           // String cache ID stored as-is
        'data',          // Complex data will be JSON encoded
        'expire',        // Integer timestamp stored as-is
        'tags',          // Array will be JSON encoded
    ],
    'returnValue'
);

// When called with:
$cache->set('my-key', ['foo' => 'bar'], time() + 3600, ['tag1', 'tag2']);

// Creates span attributes:
// drupal.cache.cid = "my-key"
// drupal.cache.data = "{\"foo\":\"bar\"}"
// drupal.cache.expire = 1677589324
// drupal.cache.tags = "[\"tag1\",\"tag2\"]"
```

### Simple Parameter Mapping
```php
// Maps the 'cid' parameter to 'drupal.cache.cid' attribute
$instrumentation->helperHook(
    'get',
    ['cid'],  // Parameter name becomes the attribute name
);

// When called with:
$cache->get('my-key');

// Creates span attribute:
// drupal.cache.cid = "my-key"
```

### Custom Attribute Names
```php
// Maps the 'cid' parameter to 'drupal.cache.key' attribute
$instrumentation->helperHook(
    'get',
    ['cid' => 'key'],  // Parameter name => custom attribute name
);

// When called with:
$cache->get('my-key');

// Creates span attribute:
// drupal.cache.key = "my-key"
```

### Multiple Parameters with Custom Names
```php
$instrumentation->helperHook(
    'set',
    [
        'cid' => 'key',        // Will be prefixed: 'drupal.cache.key'
        'data' => 'value',     // Will be prefixed: 'drupal.cache.value'
        'expire' => 'ttl',     // Will be prefixed: 'drupal.cache.ttl'
        'tags' => 'metadata'   // Will be prefixed: 'drupal.cache.metadata'
    ],
);

// When called with:
$cache->set('my-key', ['foo' => 'bar'], time() + 3600, ['tag1', 'tag2']);

// Creates span attributes:
// drupal.cache.key = "my-key"
// drupal.cache.value = "{\"foo\":\"bar\"}"
// drupal.cache.ttl = 1677589324
// drupal.cache.metadata = "[\"tag1\",\"tag2\"]"
```

### Return Value Handling
The trait can capture method return values. For example, with Drupal's cache `get` method:

```php
/**
* @see \Drupal\Core\Cache\CacheBackendInterface::get()
* Returns object|false The cache item or FALSE on failure.
*/
$instrumentation->helperHook(
    'get',
    ['cid'],
    'returnValue'  // Capture the return value under this attribute name
);

// When called with:
$cache->get('my-key');  // Returns a cache object: { data: 'cached value', expire: 1677589324 }

// Creates span attributes:
// drupal.cache.cid = "my-key"
// drupal.cache.returnValue = "{\"data\":\"cached value\",\"expire\":1677589324}"

// When called with a missing key:
$cache->get('missing-key');  // Returns FALSE

// Creates span attributes:
// drupal.cache.cid = "missing-key"
// drupal.cache.returnValue = false
```

### Combined with Custom Handlers
```php
$instrumentation->helperHook(
    'get',
    ['cid'],  // Map the cache ID parameter
    'returnValue',
    preHandler: function($spanBuilder, $object, array $params, $class, $function, $filename, $lineno) {
        // Add timestamp when cache was checked
        $spanBuilder->setAttribute(
            $this->getAttributeName('custom_time'),
            time()
        );
    },
    postHandler: function($span, $object, array $params, $returnValue, $exception) {
        // Track if this was a cache hit
        $span->setAttribute(
            $this->getAttributeName('hit'),
            $returnValue !== FALSE
        );
    }
);

// When called with:
$cache->get('my-key');  // Returns cached data

// Creates span attributes:
// drupal.cache.cid = "my-key"
// drupal.cache.custom_time = 1677589324
// drupal.cache.hit = true
// drupal.cache.returnValue = "{\"data\":\"cached value\",\"expire\":1677589324}"
```

### Error Handling
The trait automatically handles exceptions, but you can customize error handling using post handlers.

> **Note**: While these examples show detailed error capturing, in practice, OpenTelemetry tracing is best combined with proper logging. The trace ID can be added to log entries, allowing you to correlate traces with detailed log messages. This provides better observability than putting log-like information into spans.

```php
/**
* @see \Drupal\Core\Cache\CacheBackendInterface::get()
*/
$instrumentation->helperHook(
    'get',
    ['cid'],
    'returnValue',
    postHandler: function($span, $object, array $params, $returnValue, ?Throwable $exception) {
        if ($exception) {
            // Add minimal error context - detailed information should go to logs
            $span->setAttribute(
                $this->getAttributeName('error_context'),
                [
                    'cache_backend' => get_class($object),
                    'attempted_key' => $params[0] ?? null,
                ]
            );
            // Simple error categorization helps with metrics
            if ($exception instanceof \InvalidArgumentException) {
                $span->setAttribute($this->getAttributeName('error_type'), 'validation');
            } elseif ($exception instanceof \RuntimeException) {
                $span->setAttribute($this->getAttributeName('error_type'), 'connection');
            }
        } elseif ($returnValue === FALSE) {
            // Track cache misses for performance monitoring
            $span->setAttribute($this->getAttributeName('cache_miss'), true);
        }
    }
);

// Error cases are automatically handled:
try {
    $cache->get(null);  // Invalid argument
} catch (\InvalidArgumentException $e) {
    // Span will include:
    // drupal.cache.cid = null
    // exception.message = "Cache ID must be a string"
    // exception.type = "InvalidArgumentException"
    // status_code = "ERROR"
    // drupal.cache.error_type = "validation"
    // drupal.cache.error_context = "{\"cache_backend\":\"Drupal\\Core\\Cache\\DatabaseBackend\",\"attempted_key\":null}"
}

try {
    $cache->get('my-key');  // Connection error
} catch (\RuntimeException $e) {
    // Span will include:
    // drupal.cache.cid = "my-key"
    // exception.message = "Failed to connect to cache backend"
    // exception.type = "RuntimeException"
    // status_code = "ERROR"
    // drupal.cache.error_type = "connection"
    // drupal.cache.error_context = "{\"cache_backend\":\"Drupal\\Core\\Cache\\DatabaseBackend\",\"attempted_key\":\"my-key\"}"
}
```

## Requirements
- PHP 8.1+
- OpenTelemetry PHP SDK

## License
MIT License
