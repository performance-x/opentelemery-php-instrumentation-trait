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

class TestInstrumentation {
  use InstrumentationTrait {
    initialize as public;
    getInstrumentation as public;
    helperHook as public;
  }

  protected const CLASSNAME = TestTargetInterface::class;

  protected static array $testParameters = [];
  /** @var array<string, mixed> */
  protected static array $testReturnValues = [];
  protected static ?\Throwable $testException = null;
  protected static ?SpanInterface $testSpan = null;

  public static function setTestParameters(string $methodName, array $params): void {
    static::$testParameters[$methodName] = $params;
  }

  /**
   * @param mixed $value
   */
  public static function setTestReturnValue(string $methodName, mixed $value): void {
    static::$testReturnValues[$methodName] = $value;
  }

  public static function setTestException(\Throwable $exception): void {
    static::$testException = $exception;
  }

  public static function setTestSpan(SpanInterface $span): void {
    static::$testSpan = $span;
  }

  protected static function getSpanFromContext(ContextInterface $context): SpanInterface {
    if (static::$testSpan === null) {
      throw new \RuntimeException('Test span not initialized. Call setTestSpan() first.');
    }

    return static::$testSpan;
  }

  protected static function registerHook(
    string $className,
    string $methodName,
    callable $pre,
    callable $post
  ): void {
    $target = new TestTarget();
    $file = '/test/file.php';
    $line = 42;

    $params = static::$testParameters[$methodName] ?? [];

    $pre($target, $params, $className, $methodName, $file, $line);

    if ($methodName === 'throwingMethod' && static::$testException) {
      $exception = static::$testException;
      $post($target, $params, null, $exception);
    } else {
      $returnValue = static::$testReturnValues[$methodName] ?? 'test-result';
      $post($target, $params, $returnValue, null);
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
  /** @var SpanInterface&\PHPUnit\Framework\MockObject\MockObject */
  private SpanInterface $mockSpan;

  /** @var SpanBuilderInterface&\PHPUnit\Framework\MockObject\MockObject */
  private SpanBuilderInterface $mockSpanBuilder;

  /** @var TracerInterface&\PHPUnit\Framework\MockObject\MockObject */
  private TracerInterface $mockTracer;

  private TestCachedInstrumentation $testInstrumentation;

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
    TestInstrumentation::setTestSpan($this->mockSpan);
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::initialize
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::getInstrumentation
   */
  public function testInitialization(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation
    );

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::preHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::resolveParamPositions
   */
    $this->assertSame(
      $this->testInstrumentation,
      TestInstrumentation::getInstrumentation()
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::preHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::postHook
   */
  public function testParameterMapping(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    // Configure test parameters
    TestInstrumentation::setTestParameters('testMethod', [
      0 => 'param1',
      1 => ['key' => 'value']
    ]);

    $this->mockSpanBuilder->expects($this->exactly(6))
      ->method('setAttribute')
      ->withConsecutive(
        [TraceAttributes::CODE_FUNCTION, 'testMethod'],
        [TraceAttributes::CODE_NAMESPACE, TestTargetInterface::class],
        [TraceAttributes::CODE_FILEPATH, '/test/file.php'],
        [TraceAttributes::CODE_LINENO, 42],
        ['test.operation', TestTargetInterface::class . '::testMethod'],
        ['test.param1', 'param1']
      );

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      ['param1' => 'param1'],
      'returnValue'
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::preHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::postHook
   */
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

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::postHook
   */
  public function testExceptionHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation
    );

    $this->mockSpan->expects($this->once())
      ->method('recordException')
      ->with(
        $this->callback(function($exception) {
          return $exception instanceof \RuntimeException
            && $exception->getMessage() === 'Test exception';
        })
      );

    $this->mockSpan->expects($this->once())
      ->method('setStatus')
      ->with(StatusCode::STATUS_ERROR, 'Test exception');

    $exception = new \RuntimeException('Test exception');
    TestInstrumentation::setTestException($exception);

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'throwingMethod',
      []
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::postHook
   */
  public function testReturnValueHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    // Configure test parameters and return value
    TestInstrumentation::setTestParameters('testMethod', [
      0 => 'param1',
      1 => ['key' => 'value']
    ]);

    TestInstrumentation::setTestReturnValue('testMethod', 'test-result');

    $this->mockSpan->expects($this->once())
      ->method('setAttribute')
      ->with('test.result', 'test-result');

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      [],
      'result'  // Return value will be captured under this key
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::initialize
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::preHook
   */
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

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::preHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::getAttributeName
   */
  public function testAttributePrefix(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'custom.prefix'
    );

      $this->mockSpanBuilder->expects($this->exactly(5))
        ->method('setAttribute')
        ->withConsecutive(
          [TraceAttributes::CODE_FUNCTION, 'testMethod'],
          [TraceAttributes::CODE_NAMESPACE, TestTargetInterface::class],
          [TraceAttributes::CODE_FILEPATH, '/test/file.php'],
          [TraceAttributes::CODE_LINENO, 42],
          ['custom.prefix.operation', TestTargetInterface::class . '::testMethod']
        );

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      []
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::initialize
   */
  public function testInitializationValidation(): void {
    $this->expectException(\AssertionError::class);
    $this->expectExceptionMessage('Either instrumentation or name must be provided');

    TestInstrumentation::initialize();
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::preHook
   */
  public function testArrayParameterHandling(): void {
      TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    TestInstrumentation::setTestParameters('testMethod', [
      0 => 'param1',
      1 => ['nested' => ['value' => 'test']]
    ]);

    $this->mockSpanBuilder->expects($this->exactly(6))
      ->method('setAttribute')
      ->withConsecutive(
        [TraceAttributes::CODE_FUNCTION, 'testMethod'],
        [TraceAttributes::CODE_NAMESPACE, TestTargetInterface::class],
        [TraceAttributes::CODE_FILEPATH, '/test/file.php'],
        [TraceAttributes::CODE_LINENO, 42],
        ['test.operation', TestTargetInterface::class . '::testMethod'],
        ['test.param2', '{"nested":{"value":"test"}}']
      );

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      ['param2' => 'param2']
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::postHook
   */
  public function testComplexReturnValueHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    // Need to modify registerHook in TestInstrumentation to use this value
    $complexReturn = ['status' => true, 'data' => ['key' => 'value']];
    TestInstrumentation::setTestParameters('testMethod', []);
    TestInstrumentation::setTestReturnValue('testMethod', $complexReturn);

    $this->mockSpan->expects($this->once())
      ->method('setAttribute')
      ->with('test.result', '{"status":true,"data":{"key":"value"}}');

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      [],
      'result'
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::preHook
   */
  public function testMultipleParameterMapping(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    TestInstrumentation::setTestParameters('testMethod', [
      0 => 'value1',
      1 => ['key' => 'value']
    ]);

    $this->mockSpanBuilder->expects($this->exactly(7))
      ->method('setAttribute')
      ->withConsecutive(
        [TraceAttributes::CODE_FUNCTION, 'testMethod'],
        [TraceAttributes::CODE_NAMESPACE, TestTargetInterface::class],
        [TraceAttributes::CODE_FILEPATH, '/test/file.php'],
        [TraceAttributes::CODE_LINENO, 42],
        ['test.operation', TestTargetInterface::class . '::testMethod'],
        ['test.first', 'value1'],
        ['test.second', '{"key":"value"}']
      );

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      ['param1' => 'first', 'param2' => 'second']
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::preHook
   */
  public function testNonExistentParameterMapping(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    $this->mockSpanBuilder->expects($this->exactly(5))
      ->method('setAttribute')
      ->withConsecutive(
        [TraceAttributes::CODE_FUNCTION, 'testMethod'],
        [TraceAttributes::CODE_NAMESPACE, TestTargetInterface::class],
        [TraceAttributes::CODE_FILEPATH, '/test/file.php'],
        [TraceAttributes::CODE_LINENO, 42],
        ['test.operation', TestTargetInterface::class . '::testMethod']
      );

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      ['nonexistent' => 'value']
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::helperHook
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::postHook
   */
  public function testNestedExceptionHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation
    );

    $nestedException = new \RuntimeException(
      'Nested exception',
      0,
      new \Exception('Original error')
    );

    TestInstrumentation::setTestException($nestedException);

    $this->mockSpan->expects($this->once())
      ->method('recordException')
      ->with($nestedException);

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'throwingMethod',
      []
    );
  }

  /**
   * @covers \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait::initialize
   */
  public function testEmptyPrefixBehavior(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: ''
    );

    $this->mockSpanBuilder->expects($this->exactly(5))
      ->method('setAttribute')
      ->withConsecutive(
        [TraceAttributes::CODE_FUNCTION, 'testMethod'],
        [TraceAttributes::CODE_NAMESPACE, TestTargetInterface::class],
        [TraceAttributes::CODE_FILEPATH, '/test/file.php'],
        [TraceAttributes::CODE_LINENO, 42],
        ['operation', TestTargetInterface::class . '::testMethod']
      );

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      []
    );
  }
}
