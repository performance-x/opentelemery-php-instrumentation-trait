<?php

namespace PerformanceX\OpenTelemetry\Instrumentation\Tests;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\EventLoggerInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;
use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

/**
 * Test cached instrumentation class.
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

  public function meter(): MeterInterface {}
  public function logger(): LoggerInterface {}
  public function eventLogger(): EventLoggerInterface {}
}

/**
 * Test target interface.
 */
interface TestTargetInterface {
  public function testMethod(string $param1, array $param2): string;
  public function throwingMethod(): void;
}

/**
 * Test target implementation.
 */
class TestTarget implements TestTargetInterface {
  public function testMethod(string $param1, array $param2): string {
    return 'test-' . $param1;
  }
  
  public function throwingMethod(): void {
    throw new \RuntimeException('Test exception');
  }
}

/**
 * Test instrumentation implementation.
 */
class TestInstrumentation {
  use InstrumentationTrait {
    initialize as public;
    getInstrumentation as public;
    helperHook as public;
  }
  
  protected const CLASSNAME = TestTargetInterface::class;

  /**
   * Override hook registration for testing.
   */
  protected static function registerHook(
    string $className,
    string $methodName,
    callable $pre,
    callable $post
  ): void {
    $target = new TestTarget();
    $pre($target, [], $className, $methodName, __FILE__, __LINE__);
    
    try {
      if ($methodName === 'throwingMethod') {
        $exception = new \RuntimeException('Test exception');
        $post($target, [], null, $exception);
      }
      else {
        $post($target, [], 'test-result', null);
      }
    }
    catch (\Throwable $e) {
      // Ignore exceptions in tests
    }
  }
  
  public static function register(): void {
    static::initialize(
      name: 'io.opentelemetry.contrib.php.test',
      prefix: 'test'
    );
    
    static::helperHook(
      self::CLASSNAME,
      'testMethod',
      ['param1', 'param2'],
      'returnValue'
    );
    
    static::helperHook(
      self::CLASSNAME,
      'throwingMethod',
      [],
      'returnValue'
    );
  }
}

/**
 * Tests for the InstrumentationTrait.
 */
class InstrumentationTraitTest extends TestCase {
  private $mockSpan;
  private $mockSpanBuilder;
  private $mockTracer;
  private $mockScope;
  private $testInstrumentation;
  
  protected function setUp(): void {
    parent::setUp();
    
    // Mock span
    $this->mockSpan = $this->createMock(SpanInterface::class);
    
    // Mock span builder
    $this->mockSpanBuilder = $this->createMock(SpanBuilderInterface::class);
    $this->mockSpanBuilder->method('setParent')->willReturnSelf();
    $this->mockSpanBuilder->method('setSpanKind')->willReturnSelf();
    $this->mockSpanBuilder->method('setAttribute')->willReturnSelf();
    $this->mockSpanBuilder->method('startSpan')->willReturn($this->mockSpan);
    
    // Mock tracer
    $this->mockTracer = $this->createMock(TracerInterface::class);
    $this->mockTracer->method('spanBuilder')->willReturn($this->mockSpanBuilder);
    
    // Create test instrumentation
    $this->testInstrumentation = new TestCachedInstrumentation('test');
    $this->testInstrumentation->setTracer($this->mockTracer);
  }
  
  public function testInitialization(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation
    );
    
    $this->assertSame(
      $this->testInstrumentation,
      TestInstrumentation::getInstrumentation()
    );
  }
  
  public function testParameterMapping(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );
    
    $this->mockSpanBuilder->expects($this->atLeast(4))
      ->method('setAttribute')
      ->withConsecutive(
        [TraceAttributes::CODE_FUNCTION, 'testMethod'],
        [TraceAttributes::CODE_NAMESPACE, TestTargetInterface::class],
        ['test.operation', TestTargetInterface::class . '::testMethod'],
        ['test.param1', 'value1']
      );
    
    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      ['param1' => 'param1'],
      'returnValue'
    );
  }
  
  public function testCustomHandlers(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'custom'
    );
    
    $preHandlerCalled = false;
    $postHandlerCalled = false;
    
    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      [],
      null,
      preHandler: function($spanBuilder) use (&$preHandlerCalled) {
        $preHandlerCalled = true;
        $spanBuilder->setAttribute('custom.pre', 'value');
      },
      postHandler: function($span) use (&$postHandlerCalled) {
        $postHandlerCalled = true;
        $span->setAttribute('custom.post', 'value');
      }
    );
    
    $this->assertTrue($preHandlerCalled, 'Pre-handler was not called');
    $this->assertTrue($postHandlerCalled, 'Post-handler was not called');
  }
  
  public function testExceptionHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation
    );
    
    $this->mockSpan->expects($this->once())
      ->method('recordException')
      ->with($this->isInstanceOf(\RuntimeException::class));
      
    $this->mockSpan->expects($this->once())
      ->method('setStatus')
      ->with(StatusCode::STATUS_ERROR, 'Test exception');
    
    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'throwingMethod',
      []
    );
  }
  
  public function testSpanKindConfiguration(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      spanKind: SpanKind::KIND_SERVER
    );
    
    $this->mockSpanBuilder->expects($this->once())
      ->method('setSpanKind')
      ->with(SpanKind::KIND_SERVER);
    
    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      []
    );
  }
  
  public function testAttributePrefix(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'custom.prefix'
    );
    
    $this->mockSpanBuilder->expects($this->atLeastOnce())
      ->method('setAttribute')
      ->with('custom.prefix.operation', $this->anything());
    
    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      []
    );
  }
  
  public function testInitializationValidation(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Either instrumentation or name must be provided');
    
    TestInstrumentation::initialize();
  }
}
