<?php

namespace PerformanceX\OpenTelemetry\Instrumentation\Tests;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;
use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

interface TestTargetInterface {
  public function testMethod(string $param1, array $param2): string;
  public function throwingMethod(): void;
}

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
  /**
   * @var array<string, mixed>
   */
  protected static array $testReturnValues = [];
  protected static ?\Throwable $testException = NULL;
  protected static ?SpanInterface $testSpan = NULL;

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
    if (static::$testSpan === NULL) {
      throw new \RuntimeException('Test span not initialized. Call setTestSpan() first.');
    }

    return static::$testSpan;
  }

  protected static function registerHook(
    string $className,
    string $methodName,
    callable $pre,
    callable $post,
  ): void {
    $target = new TestTarget();
    $file = '/test/file.php';
    $line = 42;

    $params = static::$testParameters[$methodName] ?? [];

    $pre($target, $params, $className, $methodName, $file, $line);

    if ($methodName === 'throwingMethod' && static::$testException) {
      $post($target, $params, NULL, static::$testException);
    } else {
      $returnValue = static::$testReturnValues[$methodName] ?? 'test-result';
      $post($target, $params, $returnValue, NULL);
    }
  }

  public static function register(): void {
    static::initialize(
      name: 'io.opentelemetry.contrib.php.test',
      prefix: 'test'
    );
    static::helperHook(self::CLASSNAME, 'testMethod', ['param1', 'param2'], 'returnValue');
    static::helperHook(self::CLASSNAME, 'throwingMethod', [], 'returnValue');
  }

}

/**
 * Tests for the InstrumentationTrait.
 *
 * @coversDefaultClass \PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait
 */
class InstrumentationTraitTest extends TestCase {
  /**
   * @var \OpenTelemetry\API\Trace\SpanInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private SpanInterface $mockSpan;

  /**
   * @var \OpenTelemetry\API\Trace\SpanBuilderInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private SpanBuilderInterface $mockSpanBuilder;

  /**
   * @var \OpenTelemetry\API\Trace\TracerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private TracerInterface $mockTracer;

  private TestCachedInstrumentation $testInstrumentation;

  protected function setUp(): void {
    parent::setUp();

    // Mock span.
    $this->mockSpan = $this->createMock(SpanInterface::class);

    // Mock span builder.
    $this->mockSpanBuilder = $this->createMock(SpanBuilderInterface::class);
    $this->mockSpanBuilder->method('setParent')->willReturnSelf();
    $this->mockSpanBuilder->method('setSpanKind')->willReturnSelf();
    $this->mockSpanBuilder->method('setAttribute')->willReturnSelf();
    $this->mockSpanBuilder->method('startSpan')->willReturn($this->mockSpan);

    // Mock tracer.
    $this->mockTracer = $this->createMock(TracerInterface::class);
    $this->mockTracer->method('spanBuilder')->willReturn($this->mockSpanBuilder);

    // Create test instrumentation.
    $this->testInstrumentation = new TestCachedInstrumentation('test');
    $this->testInstrumentation->setTracer($this->mockTracer);
    TestInstrumentation::setTestSpan($this->mockSpan);
  }

  /**
   * @covers ::initialize
   * @covers ::getInstrumentation
   */
  public function testInitialization(): void {
    TestInstrumentation::initialize(instrumentation: $this->testInstrumentation);
    $this->assertSame($this->testInstrumentation, TestInstrumentation::getInstrumentation());
  }

  /**
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::postHook
   * @covers ::getSpanFromContext
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
   */
  public function testParameterMapping(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    // Configure test parameters.
    TestInstrumentation::setTestParameters('testMethod', [
      0 => 'param1',
      1 => ['key' => 'value'],
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
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::postHook
   * @covers ::getSpanFromContext
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
   */
  public function testCustomHandlers(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'custom'
    );

    $preHandlerCalled = FALSE;
    $postHandlerCalled = FALSE;

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      [],
      NULL,
      preHandler: function ($spanBuilder) use (&$preHandlerCalled) {
        $preHandlerCalled = TRUE;
        $spanBuilder->setAttribute('custom.pre', 'value');
      },
      postHandler: function ($span) use (&$postHandlerCalled) {
        $postHandlerCalled = TRUE;
        $span->setAttribute('custom.post', 'value');
      }
    );

    $this->assertTrue($preHandlerCalled, 'Pre-handler was not called');
    $this->assertTrue($postHandlerCalled, 'Post-handler was not called');
  }

  /**
   * @covers ::helperHook
   * @covers ::postHook
   * @covers ::getSpanFromContext
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::preHook
   * @covers ::resolveParamPositions
   */
  public function testExceptionHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation
    );

    $this->mockSpan->expects($this->once())
      ->method('recordException')
      ->with(
        $this->callback(function ($exception) {
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
   * @covers ::helperHook
   * @covers ::postHook
   * @covers ::getSpanFromContext
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::preHook
   * @covers ::resolveParamPositions
   */
  public function testReturnValueHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    // Configure test parameters and return value.
    TestInstrumentation::setTestParameters('testMethod', [
      0 => 'param1',
      1 => ['key' => 'value'],
    ]);

    TestInstrumentation::setTestReturnValue('testMethod', 'test-result');

    $this->mockSpan->expects($this->once())
      ->method('setAttribute')
      ->with('test.result', 'test-result');

    TestInstrumentation::helperHook(
      TestTargetInterface::class,
      'testMethod',
      [],
    // Return value will be captured under this key.
      'result'
    );
  }

  /**
   * @covers ::initialize
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
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
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
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
   * @covers ::initialize
   */
  public function testInitializationValidation(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Either instrumentation or a non-empty name must be provided');

    TestInstrumentation::initialize();
  }

  /**
   * @covers ::helperHook
   * @covers ::postHook
   * @covers ::getSpanFromContext
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::preHook
   * @covers ::resolveParamPositions
   */
  public function testArrayParameterHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    TestInstrumentation::setTestParameters('testMethod', [
      0 => 'param1',
      1 => ['nested' => ['value' => 'test']],
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
   * @covers ::helperHook
   * @covers ::postHook
   * @covers ::getSpanFromContext
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::preHook
   * @covers ::resolveParamPositions
   */
  public function testComplexReturnValueHandling(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    // Need to modify registerHook in TestInstrumentation to use this value.
    $complexReturn = ['status' => TRUE, 'data' => ['key' => 'value']];
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
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
   */
  public function testMultipleParameterMapping(): void {
    TestInstrumentation::initialize(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    TestInstrumentation::setTestParameters('testMethod', [
      0 => 'value1',
      1 => ['key' => 'value'],
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
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
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
   * @covers ::helperHook
   * @covers ::postHook
   * @covers ::getSpanFromContext
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::preHook
   * @covers ::resolveParamPositions
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
   * @covers ::initialize
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::postHook
   * @covers ::getSpanFromContext
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
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
