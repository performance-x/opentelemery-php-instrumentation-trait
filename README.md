# OpenTelemetry PHP Instrumentation Trait

A trait to simplify creating OpenTelemetry instrumentations for PHP classes and interfaces.

## Installation

```bash
composer require performance-x/opentelemetry-php-instrumentation-trait
```

## Usage

The trait is designed to be used in instrumentation classes that register OpenTelemetry hooks for specific target classes or interfaces.

```php
<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Cache\CacheBackendInterface;
use OpenTelemetry\API\Trace\SpanKind;
use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

class CacheBackendInstrumentation {
  use InstrumentationTrait;

  protected const CLASSNAME = CacheBackendInterface::class;

  public static function register(): void {
    // Initialize with name and prefix
    static::initialize(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal.cache'
    );

    // Or with custom span kind
    static::initialize(
      name: 'io.opentelemetry.contrib.php.drupal',
      prefix: 'drupal.cache',
      spanKind: SpanKind::KIND_CLIENT
    );

    // Or with pre-configured instrumentation
    static::initialize(
      instrumentation: new CachedInstrumentation('custom.name'),
      prefix: 'drupal.cache'
    );

    // Register method hooks
    static::helperHook(
      self::CLASSNAME,
      'get',
      ['cid'],
      'returnValue'
    );

    // With custom handlers
    static::helperHook(
      self::CLASSNAME,
      'set',
      ['cid', 'data', 'expire', 'tags'],
      'returnValue',
      preHandler: function($spanBuilder, $object, array $params, $class, $function, $filename, $lineno) {
        $spanBuilder->setAttribute('cache.ttl', $params[2] ?? 0);
      },
      postHandler: function($span, $object, array $params, $returnValue, $exception) {
        $span->setAttribute('cache.success', $returnValue !== FALSE);
      }
    );
  }
}
```

## Features

- Easy initialization of OpenTelemetry instrumentation
- Configurable span kind (defaults to INTERNAL)
- Automatic prefix for all span attributes
- Parameter mapping to span attributes
- Support for pre and post handlers with span access
- Automatic exception handling
- Return value capturing
- Code location attributes (function, namespace, file, line)

## Configuration Options

The `initialize()` method accepts the following parameters:

- `instrumentation`: Optional pre-configured CachedInstrumentation instance
- `prefix`: Optional prefix for all span attributes
- `spanKind`: Kind of spans to create (default: INTERNAL)
- `name`: Name of the instrumentation if no CachedInstrumentation provided

At least one of `instrumentation` or `name` must be provided.

## Hook Configuration

The `helperHook()` method accepts:

- `className`: The class or interface to instrument
- `methodName`: The method to hook
- `paramMap`: Array of parameters to capture as attributes
- `returnValueKey`: Optional key for the return value attribute
- `preHandler`: Optional callback for custom span building
- `postHandler`: Optional callback for custom span finishing

## Requirements

- PHP 8.0+
- OpenTelemetry PHP SDK

## License

MIT License
