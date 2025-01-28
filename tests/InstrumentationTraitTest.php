<?php

namespace PerformanceX\OpenTelemetry\Instrumentation\Tests;

use OpenTelemetry\API\Trace\NonRecordingSpan;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\TestCase;
use PerformanceX\OpenTelemetry\Instrumentation\InstrumentationTrait;

/**
 *
 */
interface TestTargetInterface {

  /**
   *
   */
  public function testMethod(string $param1, array $param2): string;

  /**
   *
   */
  public function throwingMethod(): void;

}

/**
 *
 */
class TestTarget implements TestTargetInterface {

  /**
   *
   */
  public function testMethod(string $param1, array $param2): string {
    return 'test-' . $param1;
  }

  /**
   *
   */
  public function throwingMethod(): void {
    throw new \RuntimeException('Test exception');
  }

}

/**
 *
 */
class TestInstrumentation {
  use InstrumentationTrait {
    getInstrumentation as public;
    helperHook as public;
    postHook as public;
    getSpanFromContext as public traitGetSpanFromContext;
    create as protected createClass;
  }

  /**
   * Creates and initializes the instrumentation.
   */
  public static function create(
    object $instrumentation = NULL,
    ?string $prefix = NULL,
    ?int $spanKind = SpanKind::KIND_INTERNAL,
    ?string $className = NULL,
    ?string $name = NULL
  ): static {
    $targetClass = $className ?? static::CLASSNAME;
    $instance = static::createClass(instrumentation: $instrumentation, prefix: $prefix, spanKind: $spanKind, className: $targetClass, name: $name);
    $instance->setTestSpan(static::$initialTestSpan);
    return $instance;
  }

  protected const CLASSNAME = TestTargetInterface::class;

  protected array $testParameters = [];
  /**
   * @var array<string, mixed>
   */
  protected array $testReturnValues = [];
  protected ?\Throwable $testException = NULL;
  protected ?SpanInterface $testSpan = NULL;

  protected static ?SpanInterface $initialTestSpan = NULL;

  /**
   *
   */
  public function setTestParameters(string $methodName, array $params): void {
    $this->testParameters[$methodName] = $params;
  }

  /**
   * @param mixed $value
   */
  public function setTestReturnValue(string $methodName, mixed $value): void {
    $this->testReturnValues[$methodName] = $value;
  }

  /**
   *
   */
  public function setTestException(\Throwable $exception): void {
    $this->testException = $exception;
  }

  /**
   *
   */
  public function setTestSpan(SpanInterface $span): void {
    $this->testSpan = $span;
  }

  public static function setInitialTestSpan(SpanInterface $span): void {
    static::$initialTestSpan = $span;
  }

  /**
   *
   */
  public function resetInstrumentation(): void {
    $this->instrumentation = NULL;
  }

  /**
   *
   */
  protected function getSpanFromContext(ContextInterface $context): SpanInterface {
    if ($this->testSpan === NULL) {
      throw new \RuntimeException('Test span not initialized. Call setTestSpan() first.');
    }

    return $this->testSpan;
  }

  /**
   *
   */
  protected function registerHook(
    string $className,
    string $methodName,
    callable $pre,
    callable $post,
  ): void {
    $target = new TestTarget();
    $file = '/test/file.php';
    $line = 42;

    $params = $this->testParameters[$methodName] ?? [];

    $pre($target, $params, $className, $methodName, $file, $line);

    if ($methodName === 'throwingMethod' && $this->testException) {
      $post($target, $params, NULL, $this->testException);
    }
    else {
      $returnValue = $this->testReturnValues[$methodName] ?? 'test-result';
      $post($target, $params, $returnValue, NULL);
    }
  }

  /**
   *
   */
  public static function register(): void {
    $instance = TestInstrumentation::create(
      name: 'io.opentelemetry.contrib.php.test',
      prefix: 'test',
      className: self::CLASSNAME
    );
    $instance->helperHook('testMethod', ['param1', 'param2'], 'returnValue');
    $instance->helperHook('throwingMethod', [], 'returnValue');
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

  public static $staticMockSpan = NULL;

  /**
   *
   */
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
    TestInstrumentation::setInitialTestSpan($this->mockSpan);
  }

  /**
   * @covers ::create
   * @covers ::initialize
   * @covers ::getInstrumentation
   */
  public function testInitialization(): void {
    $testInstrumentation = TestInstrumentation::create(instrumentation: $this->testInstrumentation);
    $this->assertSame($this->testInstrumentation, $testInstrumentation->getInstrumentation());
  }

  /**
   * @covers ::create
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
  public function testParameterMapping(): void {
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation,
      prefix: 'test',
    );

    // Configure test parameters.
    $testInstrumentation->setTestParameters('testMethod', [
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

    $testInstrumentation->helperHook('testMethod', ['param1' => 'param1'], 'returnValue');
  }

  /**
   * @covers ::create
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
  public function testCustomHandlers(): void {
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation,
      prefix: 'custom'
    );

    $preHandlerCalled = FALSE;
    $postHandlerCalled = FALSE;

    $testInstrumentation->helperHook(
      'testMethod',
      [],
      NULL,
      function ($spanBuilder) use (&$preHandlerCalled) {
        $preHandlerCalled = TRUE;
        $spanBuilder->setAttribute('custom.pre', 'value');
      },
      function ($span) use (&$postHandlerCalled) {
        $postHandlerCalled = TRUE;
        $span->setAttribute('custom.post', 'value');
      }
    );

    $this->assertTrue($preHandlerCalled, 'Pre-handler was not called');
    $this->assertTrue($postHandlerCalled, 'Post-handler was not called');
  }

  /**
   * @covers ::create
   * @covers ::initialize
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
    $testInstrumentation = TestInstrumentation::create(
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
    $testInstrumentation->setTestException($exception);

    $testInstrumentation->helperHook('throwingMethod', []);
  }

  /**
   * @covers ::create
   * @covers ::initialize
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
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    // Configure test parameters and return value.
    $testInstrumentation->setTestParameters('testMethod', [
      0 => 'param1',
      1 => ['key' => 'value'],
    ]);

    $testInstrumentation->setTestReturnValue('testMethod', 'test-result');

    $this->mockSpan->expects($this->once())
      ->method('setAttribute')
      ->with('test.result', 'test-result');

    $testInstrumentation->helperHook('testMethod', [], 'result');
  }

  /**
   * @covers ::create
   * @covers ::initialize
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::postHook
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
   */
  public function testSpanKindConfiguration(): void {
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation,
      spanKind: SpanKind::KIND_SERVER
    );

    $this->mockSpanBuilder->expects($this->once())
      ->method('setSpanKind')
      ->with(SpanKind::KIND_SERVER);

    $testInstrumentation->helperHook('testMethod', []);
  }

  /**
   * @covers ::create
   * @covers ::initialize
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::postHook
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
   */
  public function testAttributePrefix(): void {
    $testInstrumentation = TestInstrumentation::create(
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

    $testInstrumentation->helperHook('testMethod', []);
  }

  /**
   * @covers ::create
   * @covers ::initialize
   */
  public function testInitializationValidation(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Either instrumentation or a non-empty name must be provided');

    TestInstrumentation::create();
  }

  /**
   * @covers ::create
   * @covers ::initialize
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
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    $testInstrumentation->setTestParameters('testMethod', [
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

    $testInstrumentation->helperHook('testMethod', ['param2' => 'param2']);
  }

  /**
   * @covers ::create
   * @covers ::initialize
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
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    // Need to modify registerHook in TestInstrumentation to use this value.
    $complexReturn = ['status' => TRUE, 'data' => ['key' => 'value']];
    $testInstrumentation->setTestParameters('testMethod', []);
    $testInstrumentation->setTestReturnValue('testMethod', $complexReturn);

    $this->mockSpan->expects($this->once())
      ->method('setAttribute')
      ->with('test.result', '{"status":true,"data":{"key":"value"}}');

    $testInstrumentation->helperHook('testMethod', [], 'result');
  }

  /**
   * @covers ::create
   * @covers ::initialize
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::postHook
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
   */
  public function testMultipleParameterMapping(): void {
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation,
      prefix: 'test'
    );

    $testInstrumentation->setTestParameters('testMethod', [
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

    $testInstrumentation->helperHook('testMethod', ['param1' => 'first', 'param2' => 'second']);
  }

  /**
   * @covers ::create
   * @covers ::initialize
   * @covers ::helperHook
   * @covers ::preHook
   * @covers ::postHook
   * @covers ::getAttributeName
   * @covers ::getContextStorage
   * @covers ::getCurrentContext
   * @covers ::getInstrumentation
   * @covers ::resolveParamPositions
   */
  public function testNonExistentParameterMapping(): void {
    $testInstrumentation = TestInstrumentation::create(
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

    $testInstrumentation->helperHook('testMethod', ['nonexistent' => 'value']);
  }

  /**
   * @covers ::create
   * @covers ::initialize
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
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation
    );

    $nestedException = new \RuntimeException(
      'Nested exception',
      0,
      new \Exception('Original error')
    );

    $testInstrumentation->setTestException($nestedException);

    $this->mockSpan->expects($this->once())
      ->method('recordException')
      ->with($nestedException);

    $testInstrumentation->helperHook('throwingMethod', []);
  }

  /**
   * @covers ::create
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
    $testInstrumentation = TestInstrumentation::create(
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

    $testInstrumentation->helperHook('testMethod', []);
  }

  /**
   * @covers ::create
   * @covers ::initialize
   * @covers ::getInstrumentation
   */
  public function testUninitializedInstrumentation(): void {
    $testInstrumentation = TestInstrumentation::create(name: 'test');
    $testInstrumentation->resetInstrumentation();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Instrumentation not initialized. Call initialize() first.');
    $testInstrumentation->getInstrumentation();
  }

  /**
   * @covers ::create
   * @covers ::initialize
   * @covers ::getSpanFromContext
   */
  public function testGetSpanFromContext(): void {
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation
    );

    $context = $this->createMock(ContextInterface::class);

    // This should work as expected.
    $span = $testInstrumentation->traitGetSpanFromContext($context);
    $this->assertInstanceOf(NonRecordingSpan::class, $span);
  }

  /**
   * @covers ::create
   * @covers ::initialize
   * @covers ::getContextStorage
   * @covers ::postHook
   */
  public function testPostHookWithoutScope(): void {
    $testInstrumentation = TestInstrumentation::create(
      instrumentation: $this->testInstrumentation
    );

    $post = $testInstrumentation->postHook('test.operation');

    // Call postHook with some test data.
    $post(
      new TestTarget(),
      [],
      'test-result',
      NULL
    );

    // No assertion needed as we're testing that no exception is thrown.
    $this->addToAssertionCount(1);
  }

}
