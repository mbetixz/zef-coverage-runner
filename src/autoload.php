<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Zef\\Framework\\Container\\' => __DIR__.'/Framework/Container/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file=$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($file)) { require_once $file; return; }
        }
    }
    $qualification=['QualificationStatus'=>'QualificationStatus.php','QualificationGate'=>'QualificationGate.php','QualificationEvidence'=>'QualificationEvidence.php','QualificationGateResult'=>'QualificationGateResult.php','QualificationLedger'=>'QualificationLedger.php'];
    if (str_starts_with($class,'Zef\\Framework\\Qualification\\')) { $name=substr($class,strlen('Zef\\Framework\\Qualification\\')); if (isset($qualification[$name])) { require_once __DIR__.'/Framework/Qualification/'.$qualification[$name]; return; } }
    $runtime=['RuntimeInterface'=>'RuntimeInterface.php','WorkerInterface'=>'WorkerInterface.php','RoadRunnerWorkerAdapter'=>'RoadRunnerWorkerAdapter.php','RoadRunnerRuntime'=>'RoadRunnerRuntime.php','InMemoryWorker'=>'InMemoryWorker.php','BlockingSleeper'=>'BlockingSleeper.php','RuntimeExtensionState'=>'RuntimeExtensionState.php','RuntimeIdentity'=>'RuntimeIdentity.php','RuntimeExtensionContext'=>'RuntimeExtensionContext.php','RuntimeExtensionInterface'=>'RuntimeExtensionInterface.php','RuntimeExtensionRegistry'=>'RuntimeExtensionRegistry.php'];
    $transport=['TransportOutcome'=>'TransportOutcome.php','CancellationTokenInterface'=>'CancellationTokenInterface.php','NeverCancelledToken'=>'NeverCancelledToken.php','RemoteRequest'=>'RemoteRequest.php','TransportContext'=>'TransportContext.php','RemoteTransportResult'=>'RemoteTransportResult.php','RemoteTransportInterface'=>'RemoteTransportInterface.php'];
    $delivery=['DeliverySafety'=>'DeliverySafety.php','DeliveryMode'=>'DeliveryMode.php','DeliveryState'=>'DeliveryState.php','ExecutionCertainty'=>'ExecutionCertainty.php','IdempotencyClaim'=>'IdempotencyClaim.php','RetryDecisionReason'=>'RetryDecisionReason.php','DeliveryOperation'=>'DeliveryOperation.php','DeliveryObservation'=>'DeliveryObservation.php','DeliveryAttempt'=>'DeliveryAttempt.php','RetryPolicy'=>'RetryPolicy.php','RetryContext'=>'RetryContext.php','RetryDecision'=>'RetryDecision.php','IdempotencyGuaranteeInterface'=>'IdempotencyGuaranteeInterface.php','IdempotencyStoreInterface'=>'IdempotencyStoreInterface.php','IdempotencyRecord'=>'IdempotencyRecord.php','BoundedInMemoryIdempotencyStore'=>'BoundedInMemoryIdempotencyStore.php','DeliveryReconciliationInterface'=>'DeliveryReconciliationInterface.php','DeliveryResult'=>'DeliveryResult.php','DeliveryStateMachine'=>'DeliveryStateMachine.php','DeliverySemanticsEvaluator'=>'DeliverySemanticsEvaluator.php'];    if (str_starts_with($class,'Zef\\Framework\\Delivery\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Delivery\\'));
        if (isset($delivery[$name])) { require_once __DIR__.'/Framework/Delivery/'.$delivery[$name]; return; }
    }
    if (str_starts_with($class,'Zef\\Framework\\Transport\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Transport\\'));
        if (isset($transport[$name])) { require_once __DIR__.'/Framework/Transport/'.$transport[$name]; return; }
    }
    if (str_starts_with($class,'Zef\\Framework\\Runtime\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Runtime\\'));
        if (isset($runtime[$name])) { require_once __DIR__.'/Framework/Runtime/'.$runtime[$name]; return; }
    }
    $http=[
        'Stream'=>'Stream.php','Uri'=>'Uri.php','MessageBase'=>'MessageBase.php',
        'Request'=>'Request.php','ServerRequest'=>'ServerRequest.php','Response'=>'Response.php','UploadedFile'=>'UploadedFile.php',
        'Psr17Factory'=>'Psr17Factory.php',
        'RequestBodyPolicy'=>'RequestBodyPolicy.php','LimitedInputStream'=>'LimitedInputStream.php','RequestFactory'=>'RequestFactory.php',
    ];
    if (str_starts_with($class,'Zef\\Framework\\Http\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Http\\'));
        if (isset($http[$name])) require_once __DIR__.'/Framework/Http/'.$http[$name];
    }
    $security=['SecurityPolicy'=>'SecurityPolicy.php','SecurityContext'=>'SecurityContext.php','RateLimitDecision'=>'RateLimitDecision.php','RateLimiterInterface'=>'RateLimiterInterface.php','InMemoryRateLimiter'=>'InMemoryRateLimiter.php','ApcuRateLimiter'=>'ApcuRateLimiter.php','RedisRateLimiter'=>'RedisRateLimiter.php','SharedRateLimitStoreInterface'=>'SharedRateLimitStoreInterface.php','RedisSharedRateLimitStore'=>'RedisSharedRateLimitStore.php','ClientAddressResolver'=>'ClientAddressResolver.php','OriginPolicy'=>'OriginPolicy.php','CsrfTokenManager'=>'CsrfTokenManager.php','SecurityRuntimeMiddleware'=>'SecurityRuntimeMiddleware.php'];
    $distributedSecurity=['AuthenticationStatus'=>'Distributed/AuthenticationStatus.php','ReplayDecision'=>'Distributed/ReplayDecision.php','SecurityVerdict'=>'Distributed/SecurityVerdict.php','SecurityFailure'=>'Distributed/SecurityFailure.php','CredentialHandle'=>'Distributed/CredentialHandle.php','SecurityContext'=>'Distributed/SecurityContext.php','SecurityRequest'=>'Distributed/SecurityRequest.php','AuthenticationResult'=>'Distributed/AuthenticationResult.php','AuthorizationResult'=>'Distributed/AuthorizationResult.php','ReplayResult'=>'Distributed/ReplayResult.php','SecurityAdmissionDecision'=>'Distributed/SecurityAdmissionDecision.php','CredentialProviderInterface'=>'Distributed/CredentialProviderInterface.php','AuthorizationPolicyInterface'=>'Distributed/AuthorizationPolicyInterface.php','ReplayProtectorInterface'=>'Distributed/ReplayProtectorInterface.php','SecurityBoundaryInterface'=>'Distributed/SecurityBoundaryInterface.php','BoundedInMemoryReplayProtector'=>'Distributed/BoundedInMemoryReplayProtector.php','DefaultSecurityBoundary'=>'Distributed/DefaultSecurityBoundary.php','StaticCredentialProvider'=>'Distributed/StaticCredentialProvider.php','AllowScopeAuthorizationPolicy'=>'Distributed/AllowScopeAuthorizationPolicy.php'];
    if (str_starts_with($class, 'Zef\\Framework\\Security\\Distributed\\')) {
        $name=substr($class, strlen('Zef\\Framework\\Security\\Distributed\\'));
        if (isset($distributedSecurity[$name])) { require_once __DIR__.'/Framework/Security/'.$distributedSecurity[$name]; return; }
    }
    if (str_starts_with($class,'Zef\\Framework\\Security\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Security\\'));
        if (isset($security[$name])) { require_once __DIR__.'/Framework/Security/'.$security[$name]; return; }
    }
    $event=['EventContext'=>'EventContext.php','EventRegistration'=>'EventRegistration.php','EventBusInterface'=>'EventBusInterface.php','EventSubscriberInterface'=>'EventSubscriberInterface.php','EventDispatchException'=>'EventDispatchException.php','EventDispatcher'=>'EventDispatcher.php'];
    $message=['MessageEnvelope'=>'MessageEnvelope.php','MessageContext'=>'MessageContext.php','MessageResult'=>'MessageResult.php','MessageBusInterface'=>'MessageBusInterface.php','MessageSerializerInterface'=>'MessageSerializerInterface.php','MessageHandlerInterface'=>'MessageHandlerInterface.php','MessageMiddlewareInterface'=>'MessageMiddlewareInterface.php','MessageTransportInterface'=>'MessageTransportInterface.php','InProcessMessageBus'=>'InProcessMessageBus.php','JsonMessageSerializer'=>'JsonMessageSerializer.php'];
    $resource=['ResourceBudget'=>'ResourceBudget.php','AdmissionDecision'=>'AdmissionDecision.php','AdmissionControllerInterface'=>'AdmissionControllerInterface.php','AdmissionSnapshot'=>'AdmissionSnapshot.php','InMemoryAdmissionController'=>'InMemoryAdmissionController.php'];
    $cache=['CacheItem'=>'CacheItem.php','CacheInterface'=>'CacheInterface.php','CacheStoreInterface'=>'CacheStoreInterface.php','CacheSerializerInterface'=>'CacheSerializerInterface.php','CacheKeyNormalizerInterface'=>'CacheKeyNormalizerInterface.php','CacheClockInterface'=>'CacheClockInterface.php','SystemCacheClock'=>'SystemCacheClock.php','DefaultCacheKeyNormalizer'=>'DefaultCacheKeyNormalizer.php','InMemoryCacheStore'=>'InMemoryCacheStore.php','InMemoryCache'=>'InMemoryCache.php'];
    if (str_starts_with($class,'Zef\\Framework\\Cache\\')) { $name=substr($class,strlen('Zef\\Framework\\Cache\\')); if (isset($cache[$name])) { require_once __DIR__.'/Framework/Cache/'.$cache[$name]; return; } }
    $job=['JobContext'=>'JobContext.php','RetryPolicy'=>'RetryPolicy.php','JobEnvelope'=>'JobEnvelope.php','JobResult'=>'JobResult.php','JobInterface'=>'JobInterface.php','JobQueueInterface'=>'JobQueueInterface.php','JobHandlerInterface'=>'JobHandlerInterface.php','JobMiddlewareInterface'=>'JobMiddlewareInterface.php','JobIdempotencyStoreInterface'=>'JobIdempotencyStoreInterface.php','JobExecutionException'=>'JobExecutionException.php','JobCancelledException'=>'JobCancelledException.php','JobTimeoutException'=>'JobTimeoutException.php','InMemoryJobQueue'=>'InMemoryJobQueue.php','InMemoryJobIdempotencyStore'=>'InMemoryJobIdempotencyStore.php','InProcessJobWorker'=>'InProcessJobWorker.php'];
    if (str_starts_with($class,'Zef\\Framework\\Job\\')) { $name=substr($class,strlen('Zef\\Framework\\Job\\')); if (isset($job[$name])) { require_once __DIR__.'/Framework/Job/'.$job[$name]; return; } }
    if (str_starts_with($class,'Zef\\Framework\\Message\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Message\\'));
        if (isset($message[$name])) { require_once __DIR__.'/Framework/Message/'.$message[$name]; return; }
    }
    if (str_starts_with($class,'Zef\\Framework\\Resource\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Resource\\')); 
        if (isset($resource[$name])) { require_once __DIR__.'/Framework/Resource/'.$resource[$name]; return; }
    }
    if (str_starts_with($class,'Zef\\Framework\\Event\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Event\\'));
        if (isset($event[$name])) { require_once __DIR__.'/Framework/Event/'.$event[$name]; return; }
    }
    $observability=['MetricExporterInterface'=>'MetricExporterInterface.php','LogRecord'=>'LogRecord.php','LogExporterInterface'=>'LogExporterInterface.php','SpanExporterInterface'=>'SpanExporterInterface.php','SpanInterface'=>'SpanInterface.php','TracerInterface'=>'TracerInterface.php','MeterInterface'=>'MeterInterface.php','SpanContext'=>'SpanContext.php','TraceContextPropagator'=>'TraceContextPropagator.php','SpanData'=>'SpanData.php','Span'=>'Span.php','NoopSpan'=>'NoopSpan.php','NoopTracer'=>'NoopTracer.php','InMemorySpanExporter'=>'InMemorySpanExporter.php','BatchSpanProcessor'=>'BatchSpanProcessor.php','RetryBackoffPolicy'=>'RetryBackoffPolicy.php','Tracer'=>'Tracer.php','CounterMeter'=>'CounterMeter.php','TelemetryLogger'=>'TelemetryLogger.php','Telemetry'=>'Telemetry.php','TelemetrySanitizer'=>'TelemetrySanitizer.php','TelemetryClock'=>'TelemetryClock.php','OtlpHttpJsonExporter'=>'OtlpHttpJsonExporter.php','CorrelationContext'=>'CorrelationContext.php','CorrelationHeaders'=>'CorrelationHeaders.php','CorrelationPropagator'=>'CorrelationPropagator.php','CorrelationContextCarrierInterface'=>'CorrelationContextCarrierInterface.php','ExplicitCorrelationContextCarrier'=>'ExplicitCorrelationContextCarrier.php'];
    if (str_starts_with($class,'Zef\\Framework\\Observability\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Observability\\'));
        if (isset($observability[$name])) { require_once __DIR__.'/Framework/Observability/'.$observability[$name]; return; }
    }
    $cqrs=['CqrsContext'=>'CqrsContext.php','CqrsEventResult'=>'CqrsEventResult.php','CommandHandlerInterface'=>'CommandHandlerInterface.php','QueryHandlerInterface'=>'QueryHandlerInterface.php','CqrsMiddlewareInterface'=>'CqrsMiddlewareInterface.php','IdempotencyStoreInterface'=>'IdempotencyStoreInterface.php','InMemoryIdempotencyStore'=>'InMemoryIdempotencyStore.php','CommandBusInterface'=>'CommandBusInterface.php','QueryBusInterface'=>'QueryBusInterface.php','CqrsHandlerNotFoundException'=>'CqrsHandlerNotFoundException.php','CqrsHandlerConflictException'=>'CqrsHandlerConflictException.php','CommandBus'=>'CommandBus.php','QueryBus'=>'QueryBus.php'];
    if (str_starts_with($class,'Zef\\Framework\\CQRS\\')) { $name=substr($class,strlen('Zef\\Framework\\CQRS\\')); if (isset($cqrs[$name])) { require_once __DIR__.'/Framework/CQRS/'.$cqrs[$name]; return; } }
    $router=['RouteDefinition'=>'RouteDefinition.php','Router'=>'Router.php','RoutePatternParser'=>'RoutePatternParser.php','RouteOrdering'=>'RouteOrdering.php','RadixIndex'=>'RadixIndex.php','RouteMatcher'=>'RouteMatcher.php'];
    if (str_starts_with($class,'Zef\\Framework\\Router\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Router\\'));
        if (isset($router[$name])) require_once __DIR__.'/Framework/Router/'.$router[$name];
    }
    // Phase 4: MiddlewareDefinition / PipelineFactory / MiddlewarePipeline stay in
    // the Zef\Framework root namespace but are physically extracted alongside the
    // other middleware artefacts under src/Framework/Middleware/. PipelineFactory is
    // now one-class-per-file (D2).
    $frameworkMiddleware=['MiddlewareDefinition'=>'MiddlewareDefinition.php','PipelineFactory'=>'PipelineFactory.php','MiddlewarePipeline'=>'MiddlewarePipeline.php'];
    // Phase 5: ModuleBootstrapper / Dispatcher / ResponseEmitter / Application stay
    // in the Zef\Framework root namespace but are physically extracted under
    // src/Framework/Application/. After Phase 5 the Zef\Framework root namespace is
    // fully served from modular source (Middleware + Application slices).
    $frameworkApplication=['ModuleBootstrapper'=>'ModuleBootstrapper.php','Dispatcher'=>'Dispatcher.php','ResponseEmitter'=>'ResponseEmitter.php','Application'=>'Application.php'];
    if (str_starts_with($class,'Zef\\Framework\\') && !str_starts_with($class,'Zef\\Framework\\Container\\') && !str_starts_with($class,'Zef\\Framework\\Http\\') && !str_starts_with($class,'Zef\\Framework\\Router\\')) {
        $short=substr($class,strlen('Zef\\Framework\\'));
        if (isset($frameworkMiddleware[$short])) {
            require_once __DIR__.'/Framework/'.$frameworkMiddleware[$short];
            return;
        }
        if (isset($frameworkApplication[$short])) {
            require_once __DIR__.'/Framework/'.$frameworkApplication[$short];
            return;
        }
    }

    $validation = [
        'HeaderValidator' => 'HeaderValidator.php',
        'HttpStatusValidator' => 'HttpStatusValidator.php',
        'TrustedHostValidator' => 'TrustedHostValidator.php',
        'PortRangeValidator' => 'PortRangeValidator.php',
        'HttpMethodValidator' => 'HttpMethodValidator.php',
        'RouteConstraintValidator' => 'RouteConstraintValidator.php',
        'DependencyGraphValidator' => 'DependencyGraphValidator.php',
    ];
    if (str_starts_with($class, 'Zef\\Framework\\Validation\\')) {
        $name = substr($class, strlen('Zef\\Framework\\Validation\\'));
        if (isset($validation[$name])) {
            require_once __DIR__.'/Framework/Validation/'.$validation[$name];
            return;
        }
    }
    $legacy = [
        "Zef\\Framework\\Exception\\" => __DIR__.'/Framework/Exception/Exceptions.php',
        "Zef\\Framework\\Constant\\" => __DIR__.'/Framework/Constant/HttpReasonPhrases.php',
        "Zef\\Framework\\Config\\" => __DIR__.'/Framework/Config/Config.php',
        "Zef\\Framework\\Policy\\" => __DIR__.'/Framework/Policy/ArchitecturePolicy.php',
        "Zef\\Module\\Core\\" => __DIR__.'/Module/Core.php',
        "Zef\\Module\\Health\\" => __DIR__.'/Module/Health.php',
        "Zef\\Plugin\\Toko\\" => __DIR__.'/Plugin/Toko.php',
    ];
    $config=['ModuleDefinition'=>'ModuleDefinition.php','ModuleRegistrar'=>'ModuleRegistrar.php','ModuleContext'=>'ModuleContext.php','ModuleInterface'=>'ModuleInterface.php','AbstractModule'=>'AbstractModule.php','ConfigProviderModule'=>'ConfigProviderModule.php','ModuleRegistry'=>'ModuleRegistry.php','ModuleConfigProvider'=>'ModuleConfigProvider.php','ConfigProviderInterface'=>'ConfigProviderInterface.php','ConfigAggregator'=>'ConfigAggregator.php','SecretValue'=>'SecretValue.php','SecretProviderInterface'=>'SecretProviderInterface.php','EnvironmentSecretProvider'=>'EnvironmentSecretProvider.php','ConfigurationSnapshot'=>'ConfigurationSnapshot.php','ConfigurationGovernance'=>'ConfigurationGovernance.php'];
    if (str_starts_with($class,'Zef\\Framework\\Config\\')) {
        $name=substr($class,strlen('Zef\\Framework\\Config\\'));
        if (isset($config[$name])) { require_once __DIR__.'/Framework/Config/'.$config[$name]; return; }
    }
    foreach ($legacy as $prefix => $file) {
        if (str_starts_with($class, $prefix)) {
            require_once $file;
            return;
        }
    }
    $application = [
        "Zef\\App\\" => __DIR__.'/App/App.php',
        "Zef\\Test\\" => __DIR__.'/Test/Test.php',
    ];
    foreach ($application as $prefix => $file) {
        if (str_starts_with($class, $prefix)) {
            require_once $file;
            return;
        }
    }
    // Concrete PSR-15 middlewares + middleware ConfigProvider (Zef\Middleware namespace).
    $middleware=[
        'ErrorLogger'=>'Middlewares.php','ErrorResponseFactory'=>'Middlewares.php','GlobalErrorHandler'=>'Middlewares.php',
        'TimingMiddleware'=>'Middlewares.php','CorsMiddleware'=>'Middlewares.php','SecurityHeadersMiddleware'=>'Middlewares.php',
        'ConfigProvider'=>'Middlewares.php',
    ];
    if (str_starts_with($class,'Zef\\Middleware\\')) {
        $name=substr($class,strlen('Zef\\Middleware\\'));
        if (isset($middleware[$name])) require_once __DIR__.'/Middleware/'.$middleware[$name];
    }
});
require_once __DIR__.'/kernel.php';