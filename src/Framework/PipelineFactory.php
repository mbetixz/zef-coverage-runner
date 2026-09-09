<?php

declare(strict_types=1);

namespace Zef\Framework {

    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Zef\Framework\Config\ConfigAggregator;
    use Zef\Framework\Container\Container;
    use Zef\Framework\Exception\InvalidConfigurationException;

    final class PipelineFactory
    {
        public function __construct(private readonly Container $container, private readonly ConfigAggregator $config, private readonly RequestHandlerInterface $terminal)
        {
        }
        public function build(): MiddlewarePipeline
        {
            $entries = $this->config->get('middleware.stack', []);
            if (!is_array($entries)) {
                $entries = [];
            }
            /** @var list<mixed> $entries */
            $entries = array_values($entries);
            $pipeline = new MiddlewarePipeline([], $this->terminal);
            $definitions = [];
            foreach ($entries as $entry) {
                try {
                    /** @var string|array<string,mixed> $entry */
                    $definitions[] = \Zef\Framework\MiddlewareDefinition::fromLegacy($entry);
                } catch (\Throwable $e) {
                    throw new InvalidConfigurationException($e->getMessage(), 0, $e);
                }
            }
            foreach ($definitions as $definition) {
                $id = $definition->serviceId;
                if (!$this->container->has($id)) {
                    throw new InvalidConfigurationException("Middleware service '{$id}' is not registered.");
                }$mw = $this->container->get($id);
                if (!$mw instanceof MiddlewareInterface) {
                    throw new InvalidConfigurationException("Service '{$id}' does not implement MiddlewareInterface.");
                }$pipeline = $pipeline->withMiddleware($mw);
            } return $pipeline;
        }
    }
}
