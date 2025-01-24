<?php

namespace PerformanceX\OpenTelemetry\Instrumentation;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use function OpenTelemetry\Instrumentation\hook;
use ReflectionMethod;
use Throwable;

trait InstrumentationTrait {
  protected static $instrumentation = null;
  protected static ?string $attributePrefix = null;
  protected static int $spanKind = SpanKind::KIND_INTERNAL;

  /**
   * Initialize the instrumentation with configuration options.
   *
   * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation|null $instrumentation
   *   Optional pre-configured instrumentation.
   * @param string|null $prefix
   *   Prefix for all span attributes.
   * @param \OpenTelemetry\API\Trace\SpanKind $spanKind
   *   Kind of spans to create (default: INTERNAL).
   * @param string|null $name
   *   Name of the instrumentation if no CachedInstrumentation provided.
   *
   * @throws \RuntimeException
   *   When neither instrumentation nor name is provided.
   */
  protected static function initialize(
    $instrumentation = null,
    ?string $prefix = null,
    ?int $spanKind = SpanKind::KIND_INTERNAL,
    ?string $name = null,
  ): void {
    if ($instrumentation === null && $name === null) {
      throw new \RuntimeException('Either instrumentation or name must be provided');
    }
    static::$instrumentation = $instrumentation ?? new CachedInstrumentation($name);
    static::$attributePrefix = $prefix;
    static::$spanKind = $spanKind;
  }

  protected static function getAttributeName(string $name): string {
    return static::$attributePrefix !== null
      ? static::$attributePrefix . '.' . $name
      : $name;
  }

  protected static function getInstrumentation() {
    if (static::$instrumentation === null) {
      throw new \RuntimeException('Instrumentation not initialized. Call initialize() first.');
    }
    return static::$instrumentation;
  }

  protected static function helperHook(
    string $className,
    string $methodName,
    array $paramMap = [],
    ?string $returnValueKey = null,
    ?callable $preHandler = null,
    ?callable $postHandler = null
  ): void {
    $resolvedParamMap = static::resolveParamPositions($className, $methodName, $paramMap);
    static::registerHook(
      $className,
      $methodName,
      pre: static::preHook("$className::$methodName", $resolvedParamMap, $preHandler),
      post: static::postHook("$className::$methodName", $returnValueKey, $postHandler)
    );
  }

  protected static function preHook(
    string $operation,
    array $resolvedParamMap = [],
    ?callable $customHandler = null
  ): callable {
    return static function (
      $object,
      array $params,
      string $class,
      string $function,
      ?string $filename,
      ?int $lineno
    ) use ($operation, $resolvedParamMap, $customHandler): void {
      $parent = Context::getCurrent();
      $spanBuilder = static::getInstrumentation()->tracer()->spanBuilder("$class::$function")
        ->setParent($parent)
        ->setSpanKind(static::$spanKind)
        ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
        ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
        ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
        ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

      $spanBuilder->setAttribute(static::getAttributeName('operation'), $operation);

      foreach ($resolvedParamMap as $attributeName => $position) {
        if (isset($params[$position])) {
          $value = $params[$position];
          $spanBuilder->setAttribute(
            static::getAttributeName($attributeName),
            is_scalar($value) ? $value : json_encode($value)
          );
        }
      }

      if ($customHandler !== null) {
        $customHandler($spanBuilder, $object, $params, $class, $function, $filename, $lineno);
      }

      $span = $spanBuilder->startSpan();
      Context::storage()->attach($span->storeInContext($parent));
    };
  }

  protected static function postHook(
    string $operation,
    ?string $resultAttribute = null,
    ?callable $customHandler = null
  ): callable {
    return static function (
      $object,
      array $params,
      $returnValue,
      ?Throwable $exception
    ) use ($operation, $resultAttribute, $customHandler): void {
      $scope = Context::storage()->scope();
      if (!$scope) {
        return;
      }

      $span = Span::fromContext($scope->context());

      if ($resultAttribute !== null) {
        $span->setAttribute(
          static::getAttributeName($resultAttribute),
          is_scalar($returnValue) ? $returnValue : json_encode($returnValue)
        );
      }

      if ($exception) {
        $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => TRUE]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
      }

      if ($customHandler !== null) {
        $customHandler($span, $object, $params, $returnValue, $exception);
      }

      $span->end();
      $scope->detach();
    };
  }

  protected static function resolveParamPositions(
    string $className,
    string $methodName,
    array $paramMap
  ): array {
    if (empty($paramMap)) {
      return [];
    }

    $reflection = new ReflectionMethod($className, $methodName);
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
   */
  protected static function registerHook(
    string $className,
    string $methodName,
    callable $pre,
    callable $post
  ): void {
    hook($className, $methodName, pre: $pre, post: $post);
  }
}
