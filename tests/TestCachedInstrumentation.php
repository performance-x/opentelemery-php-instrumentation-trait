<?php

namespace PerformanceX\OpenTelemetry\Instrumentation\Tests;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\EventLoggerInterface;

/**
 * Test version of CachedInstrumentation that allows injection of mocks.
 */
class TestCachedInstrumentation {
  private TracerInterface $tracer;

  /**
   * @param string $name
   *   Required by CachedInstrumentation interface.
   */
  public function __construct(
    /**
     * @phpstan-ignore-next-line */
    private readonly string $name,
    /**
     * @phpstan-ignore-next-line */
    private readonly ?string $version = NULL,
    /**
     * @phpstan-ignore-next-line */
    private readonly ?string $schemaUrl = NULL,
    /**
     * @phpstan-ignore-next-line */
    private readonly iterable $attributes = [],
  ) {}

  /**
   *
   */
  public function setTracer(TracerInterface $tracer): void {
    $this->tracer = $tracer;
  }

  /**
   *
   */
  public function tracer(): TracerInterface {
    return $this->tracer;
  }

  /**
   * @throws \RuntimeException
   */
  public function meter(): MeterInterface {
    throw new \RuntimeException('Not implemented in test class');
  }

  /**
   * @throws \RuntimeException
   */
  public function logger(): LoggerInterface {
    throw new \RuntimeException('Not implemented in test class');
  }

  /**
   * @throws \RuntimeException
   */
  public function eventLogger(): EventLoggerInterface {
    throw new \RuntimeException('Not implemented in test class');
  }

}
