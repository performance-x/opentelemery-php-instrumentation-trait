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

  public function __construct(
    private readonly string $name,
    private readonly ?string $version = null,
    private readonly ?string $schemaUrl = null,
    private readonly iterable $attributes = [],
  ) {}

  public function setTracer(TracerInterface $tracer): void {
    $this->tracer = $tracer;
  }

  public function tracer(): TracerInterface {
    return $this->tracer;
  }

  // Add these if needed later
  public function meter(): MeterInterface {}
  public function logger(): LoggerInterface {}
  public function eventLogger(): EventLoggerInterface {}
}
