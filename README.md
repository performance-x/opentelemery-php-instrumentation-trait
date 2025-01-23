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

namespace MyNamespace\Instrumentation;

use MyNamespace\TargetInterface;
use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

class TargetInstrumentation
{
    use InstrumentationTrait;

    protected const CLASSNAME = TargetInterface::class;

    public static function register(): void
    {
        // Initialize with your instrumentation name
        static::initializeInstrumentation('io.opentelemetry.contrib.php.mypackage');

        // Set prefix for all span attributes (optional)
        static::initializePrefix('mypackage.component');

        // Register hooks for methods
        static::helperHook(
            self::CLASSNAME,
            'methodName',
            ['param1', 'param2'],  // Parameters to capture
            'returnValue'          // Capture return value
        );

        // With custom handlers
        static::helperHook(
            self::CLASSNAME,
            'otherMethod',
            ['param1' => 'custom.name'],
            'result',
            preHandler: function($spanBuilder, $object, array $params, $class, $function, $filename, $lineno) {
                $spanBuilder->setAttribute('custom.attribute', 'value');
            },
            postHandler: function($span, $object, array $params, $returnValue, $exception) {
                $span->setAttribute('custom.result', $returnValue);
            }
        );
    }
}
```

## Features

- Easy initialization of OpenTelemetry instrumentation
- Automatic parameter mapping to span attributes
- Custom prefix for all span attributes
- Support for pre and post handlers
- Automatic exception handling
- Return value capturing
- Code location attributes (function, namespace, file, line)

## Requirements

- PHP 8.0+
- OpenTelemetry PHP SDK

## License

MIT License