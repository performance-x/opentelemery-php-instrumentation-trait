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
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
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

/**
 * Test instrumentation implementation.
 */
class TestInstrumentation {
  use InstrumentationTrait {
    initialize as public;
    getInstrumentation as public;
    helperHook as public;
  }

  protected static function registerHook(
    string $className,
    string $methodName,
    callable $pre,
    callable $post
  ): void {
    // Immediately execute the pre and post hooks for testing
    $target = new TestTarget();
    $pre($target, [], $className, $methodName, __FILE__, __LINE__);
    $post($target, [], 'test-result', null);
  }

  protected const CLASSNAME = TestTargetInterface::class;
  
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
  
  // Mock scope with context
  $mockContext = $this->createMock(ContextInterface::class);
  $this->mockScope = $this->getMockBuilder(ScopeInterface::class)
    ->getMock();
  $this->mockScope->method('detach')->willReturn(0);
}  
  
  public function testInitialization(): void {
    TestInstrumentation::register();
    
    $this->assertInstanceOf(
      CachedInstrumentation::class,
      TestInstrumentation::getInstrumentation()
    );
  }
  
  public function testParameterMapping(): void {
    TestInstrumentation::register();
    
    $this->mockSpanBuilder->expects($this->atLeast(4))
      ->method('setAttribute')
      ->withConsecutive(
        [TraceAttributes::CODE_FUNCTION, 'testMethod'],
        [TraceAttributes::CODE_NAMESPACE, TestTargetInterface::class],
        ['test.operation', TestTargetInterface::class . '::testMethod'],
        ['test.param1', 'value1']
      );
      
    $target = new TestTarget();
    $target->testMethod('value1', ['key' => 'value']);
  }
  
  public function testCustomHandlers(): void {
    TestInstrumentation::initialize(
      name: 'test',
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
    
    $target = new TestTarget();
    $target->testMethod('test', []);
    
    $this->assertTrue($preHandlerCalled, 'Pre-handler was not called');
    $this->assertTrue($postHandlerCalled, 'Post-handler was not called');
  }
  
  public function testExceptionHandling(): void {
    TestInstrumentation::register();
    
    $this->mockSpan->expects($this->once())
      ->method('recordException')
      ->with($this->isInstanceOf(\RuntimeException::class));
      
    $this->mockSpan->expects($this->once())
      ->method('setStatus')
      ->with(StatusCode::STATUS_ERROR, 'Test exception');
      
    $target = new TestTarget();
    try {
      $target->throwingMethod();
    }
    catch (\RuntimeException $e) {
      // Expected
    }
  }
  
  public function testSpanKindConfiguration(): void {
    TestInstrumentation::initialize(
      name: 'test',
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
      
    $target = new TestTarget();
    $target->testMethod('test', []);
  }
  
  public function testAttributePrefix(): void {
    TestInstrumentation::initialize(
      name: 'test',
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
      
    $target = new TestTarget();
    $target->testMethod('test', []);
  }
  
  public function testInitializationValidation(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Either instrumentation or name must be provided');
    
    TestInstrumentation::initialize();
  }
}
