<?php

namespace PerformanceX\OpenTelemetry\Instrumentation;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use function OpenTelemetry\Instrumentation\hook;

/**
 *
 */
trait InstrumentationTrait {
  /**
   * @var object|null*/
  protected ?object $instrumentation = NULL;
  protected ?string $attributePrefix = NULL;

  /**
   * @var \OpenTelemetry\API\Trace\SpanKind::KIND_* */
  protected int $spanKind = SpanKind::KIND_INTERNAL;
  protected ?string $className = NULL;

  public const UPDATE_NAME = 'X-PerformanceX-OpenTelemetry-Update-Name';

  /**
   * Create new instrumentation with configuration options.
   *
   * @param string|null $name
   *   Name of the instrumentation if no CachedInstrumentation provided.
   * @param string|null $prefix
   *   Prefix for all span attributes.
   * @param \OpenTelemetry\API\Trace\SpanKind::KIND_* $spanKind
   *   Kind of spans to create (default: INTERNAL).
   * @param object|null $instrumentation
   *   Optional pre-configured instrumentation.
   *
   * @throws \RuntimeException
   *   When neither instrumentation nor name is provided.
   */
  public static function create(
    ?string $name = NULL,
    ?string $prefix = NULL,
    ?int $spanKind = SpanKind::KIND_INTERNAL,
    ?object $instrumentation = NULL,
    ?string $className = NULL,
  ): static {
    $instance = new static();
    $instance->className = $className;
    $instance->initialize(instrumentation: $instrumentation, prefix: $prefix, spanKind: $spanKind, name: $name);
    return $instance;
  }

  /**
 * Final constructor, so we can use a factory method above.
 */
  final public function __construct() {
  }

  /**
   * Initialize the instrumentation with configuration options.
   *
   * @param object|null $instrumentation
   *   Optional pre-configured instrumentation.
   * @param string|null $prefix
   *   Prefix for all span attributes.
   * @param \OpenTelemetry\API\Trace\SpanKind::KIND_* $spanKind
   *   Kind of spans to create (default: INTERNAL).
   * @param string|null $name
   *   Name of the instrumentation if no CachedInstrumentation provided.
   *
   * @throws \RuntimeException
   *   When neither instrumentation nor name is provided.
   */
  protected function initialize(
    $instrumentation = NULL,
    ?string $prefix = NULL,
    ?int $spanKind = SpanKind::KIND_INTERNAL,
    ?string $name = NULL,
  ): void {
    if ($instrumentation === NULL && empty($name)) {
      throw new \RuntimeException('Either instrumentation or a non-empty name must be provided');
    }

    if ($instrumentation !== NULL) {
      assert(method_exists($instrumentation, 'tracer'), 'Instrumentation must implement tracer() method');
    }

    assert(
      in_array($spanKind, [
        SpanKind::KIND_INTERNAL,
        SpanKind::KIND_CLIENT,
        SpanKind::KIND_SERVER,
        SpanKind::KIND_PRODUCER,
        SpanKind::KIND_CONSUMER,
      ], TRUE),
      'Invalid span kind provided'
    );
    $this->instrumentation = $instrumentation ?? new CachedInstrumentation($name);
    $this->attributePrefix = $prefix;
    $this->spanKind = $spanKind;
  }

  /**
   * @param string $name
   * @return non-empty-string
   */
  protected function getAttributeName(string $name): string {
    assert(!empty($name), 'Attribute name cannot be empty');

    if (empty($this->attributePrefix)) {
      return $name;
    }

    return $this->attributePrefix . '.' . $name;
  }

  /**
   * @return object
   * @throws \RuntimeException
   */
  protected function getInstrumentation() {
    if ($this->instrumentation === NULL) {
      throw new \RuntimeException('Instrumentation not initialized. Call initialize() first.');
    }
    return $this->instrumentation;
  }

  /**
   *
   */
  public function helperHook(
    string $methodName,
    array $paramMap = [],
    ?string $returnValueKey = NULL,
    ?callable $preHandler = NULL,
    ?callable $postHandler = NULL,
    ?string $className = NULL,
  ): self {
    $targetClass = $className ?? $this->className;
    $operation = $targetClass ? "$targetClass::$methodName" : $methodName;

    $resolvedParamMap = static::resolveParamPositions($targetClass, $methodName, $paramMap);
    static::registerHook(
      $targetClass,
      $methodName,
      pre: $this->preHook($operation, $resolvedParamMap, $preHandler),
      post: $this->postHook($operation, $returnValueKey, $postHandler)
    );

    return $this;
  }

  /**
   *
   */
  protected function preHook(
    string $operation,
    array $resolvedParamMap = [],
    ?callable $customHandler = NULL,
  ): callable {
    return function (
      $object,
      array $params,
      string $class,
      string $function,
      ?string $filename,
      ?int $lineno,
    ) use (
      $operation,
      $resolvedParamMap,
      $customHandler
): void {
      $parent = static::getCurrentContext();

      /** @var \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation */
      $instrumentation = $this->getInstrumentation();

      $spanBuilder = $instrumentation->tracer()->spanBuilder("$class::$function")
        ->setParent($parent)
        ->setSpanKind($this->spanKind)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

      $spanBuilder->setAttribute($this->getAttributeName('operation'), $operation);

      foreach ($resolvedParamMap as $attributeName => $position) {
        if (isset($params[$position])) {
          $value = $params[$position];
          $spanBuilder->setAttribute(
            $this->getAttributeName($attributeName),
            is_scalar($value) ? $value : json_encode($value)
          );
        }
      }

      if ($customHandler !== NULL) {
        $customHandler($spanBuilder, $object, $params, $class, $function, $filename, $lineno);
      }

      $span = $spanBuilder->startSpan();
      $updatedName = NULL;
      if ($span instanceof ReadWriteSpanInterface) {
        $updatedName = $span->getAttribute(static::UPDATE_NAME);
      }
      if ($updatedName) {
        $span->updateName($updatedName);
      }
      static::getContextStorage()->attach($span->storeInContext($parent));
    };
  }

  /**
   *
   */
  protected function postHook(
    string $operation,
    ?string $resultAttribute = NULL,
    ?callable $customHandler = NULL,
  ): callable {
    return function (
      $object,
      array $params,
      $returnValue,
      ?\Throwable $exception,
    ) use (
      $resultAttribute,
      $customHandler
): void {
      $scope = static::getContextStorage()->scope();
      if (!$scope) {
        return;
      }

      $span = static::getSpanFromContext($scope->context());

      if ($resultAttribute !== NULL) {
        $span->setAttribute(
          $this->getAttributeName($resultAttribute),
          is_scalar($returnValue) ? $returnValue : json_encode($returnValue)
        );
      }

      if ($exception) {
        $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => TRUE]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
      }

      if ($customHandler !== NULL) {
        $customHandler($span, $object, $params, $returnValue, $exception);
      }

      $span->end();
      $scope->detach();
    };
  }

  /**
   *
   */
  protected static function resolveParamPositions(
    ?string $className,
    string $methodName,
    array $paramMap,
  ): array {
    if (empty($paramMap)) {
      return [];
    }

    if ($className === NULL) {
      // Handle standalone functions.
      $reflection = new \ReflectionFunction($methodName);
    }
    else {
      // Handle class methods.
      $reflection = new \ReflectionMethod($className, $methodName);
    }

    $parameters = $reflection->getParameters();
    $resolvedMap = [];
    foreach ($paramMap as $key => $value) {
      $paramName = is_int($key) ? $value : $key;
      $attributeName = $value;
      foreach ($parameters as $index => $parameter) {
        if ($parameter->getName() === $paramName) {
          $resolvedMap[$attributeName] = $index;
          break;
        }
      }
    }
    return $resolvedMap;
  }

  /**
   * Protected method to allow override of hook registration in tests.
   *
   * @codeCoverageIgnore
   */
  protected static function registerHook(
    ?string $className,
    string $methodName,
    callable $pre,
    callable $post,
  ): void {
    hook($className, $methodName, pre: $pre, post: $post);
  }

  /**
   * @return \OpenTelemetry\Context\ContextInterface
   */
  protected static function getCurrentContext(): ContextInterface {
    return Context::getCurrent();
  }

  /**
   * @return \OpenTelemetry\Context\ContextStorageInterface
   */
  protected static function getContextStorage(): ContextStorageInterface {
    return Context::storage();
  }

  /**
   * @param \OpenTelemetry\Context\ContextInterface $context
   * @return \OpenTelemetry\API\Trace\SpanInterface|\OpenTelemetry\API\Trace\NonRecordingSpan
   */
  protected static function getSpanFromContext(ContextInterface $context): object {
    return Span::fromContext($context);
  }

}
