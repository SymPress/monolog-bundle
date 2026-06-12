<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\DependencyInjection;

use Monolog\Handler\BrowserConsoleHandler;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\ChromePHPHandler;
use Monolog\Handler\DeduplicationHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\NoopHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SamplingHandler;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Handler\TestHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\ResettableInterface;
use SymPress\MonologBundle\Handler\ConsoleHandler;
use SymPress\MonologBundle\Handler\FingersCrossed\HttpStatusCodeActivationStrategy;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class MonologExtension extends Extension
{
    /** @var list<string> */
    private array $nestedHandlers = [];

    public function getAlias(): string
    {
        return 'monolog';
    }

    /** @param array<int, array<string, mixed>> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->nestedHandlers = [];
        $config = $this->mergeConfigs($configs);

        if (array_key_exists('use_microseconds', $config)) {
            $container->setParameter('monolog.use_microseconds', (bool) $config['use_microseconds']);
        }

        if (array_key_exists('channels', $config)) {
            $container->setParameter(
                'monolog.additional_channels',
                $this->stringList($config['channels']),
            );
        }

        if (!array_key_exists('handlers', $config) || !is_array($config['handlers'])) {
            return;
        }

        $configuredHandlers = $this->normalizeHandlers($config['handlers']);
        $handlerEntries = [];
        $removedHandlerIds = [];
        $handlerAliases = [];
        $order = 0;

        foreach ($configuredHandlers as $name => $handler) {
            $handlerId = $this->handlerId($name);
            $configuredHandlerId = $this->configuredHandlerId($name);
            $removedHandlerIds[$handlerId] = true;
            $handlerAliases[$handlerId] = $configuredHandlerId;

            if (!$handler['enabled']) {
                unset($handlerAliases[$handlerId]);
                continue;
            }

            $handlerEntries[] = [
                'id'       => $handlerId,
                'channels' => $handler['channels'],
                'priority' => $handler['priority'],
                'order'    => $order++,
            ];
            $this->buildHandler($container, $name, $handler);
        }

        $container->setParameter('monolog.handlers_to_channels', $this->handlersToChannels($handlerEntries));
        $container->setParameter('monolog.disabled_handlers', array_keys($removedHandlerIds));
        $container->setParameter('monolog.handler_aliases', $handlerAliases);
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     * @return array<string, mixed>
     */
    private function mergeConfigs(array $configs): array
    {
        $merged = [];

        foreach ($configs as $config) {
            $merged = array_replace_recursive($merged, $config);
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $handlers
     * @return array<string, array<string, mixed>>
     */
    private function normalizeHandlers(array $handlers): array
    {
        $normalized = [];

        foreach ($handlers as $name => $handler) {
            $name = (string) $name;

            if ($name === '') {
                throw new InvalidConfigurationException('Monolog handler names must be non-empty strings.');
            }

            if (!is_array($handler)) {
                throw new InvalidConfigurationException(sprintf('Monolog handler "%s" must be an array.', $name));
            }

            $normalized[$name] = $this->normalizeHandler($name, $handler);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $handler
     * @return array<string, mixed>
     */
    private function normalizeHandler(string $name, array $handler): array
    {
        $type = $handler['type'] ?? null;

        if ($type === null) {
            $type = 'null';
        }

        if (!is_scalar($type) && !$type instanceof \Stringable) {
            throw new InvalidConfigurationException(sprintf('Monolog handler "%s" needs a valid type.', $name));
        }

        $handler['type'] = strtolower((string) $type);
        $handler['enabled'] = (bool) ($handler['enabled'] ?? true);
        $handler['priority'] = is_numeric($handler['priority'] ?? null) ? (int) $handler['priority'] : 0;
        $handler['level'] ??= 'debug';
        $handler['bubble'] = (bool) ($handler['bubble'] ?? true);
        $handler['path'] ??= '%kernel.logs_dir%/%kernel.environment%.log';
        $handler['file_permission'] = $this->filePermission($handler['file_permission'] ?? null);
        $handler['use_locking'] = (bool) ($handler['use_locking'] ?? false);
        $handler['max_files'] = is_numeric($handler['max_files'] ?? null) ? (int) $handler['max_files'] : 0;
        $handler['filename_format'] ??= '{filename}-{date}';
        $handler['date_format'] ??= 'Y-m-d';
        $handler['action_level'] ??= 'warning';
        $handler['activation_strategy'] = $this->serviceId($handler['activation_strategy'] ?? null);
        $handler['stop_buffering'] = (bool) ($handler['stop_buffering'] ?? true);
        $handler['passthru_level'] ??= null;
        $handler['buffer_size'] = is_numeric($handler['buffer_size'] ?? null) ? (int) $handler['buffer_size'] : 0;
        $handler['flush_on_overflow'] = (bool) ($handler['flush_on_overflow'] ?? false);
        $handler['handler'] = $this->serviceId($handler['handler'] ?? null);
        $handler['members'] = $this->stringList($handler['members'] ?? []);
        $handler['channels'] = $this->normalizeChannelConfiguration($handler['channels'] ?? null);
        $handler['formatter'] = $this->serviceId($handler['formatter'] ?? null);
        $handler['nested'] = (bool) ($handler['nested'] ?? false);
        $handler['include_stacktraces'] = (bool) ($handler['include_stacktraces'] ?? false);
        $handler['base_path'] = $this->nullableString($handler['base_path'] ?? null);
        $handler['process_psr_3_messages'] = $this->normalizePsrLogProcessor($handler['process_psr_3_messages'] ?? null);
        $handler['ident'] ??= 'php';
        $handler['facility'] ??= LOG_USER;
        $handler['logopts'] = is_numeric($handler['logopts'] ?? null) ? (int) $handler['logopts'] : LOG_PID;
        $handler['host'] = $this->nullableString($handler['host'] ?? null);
        $handler['port'] = is_numeric($handler['port'] ?? null) ? (int) $handler['port'] : 514;
        $handler['accepted_levels'] = $this->stringList($handler['accepted_levels'] ?? []);
        $handler['min_level'] ??= 'debug';
        $handler['max_level'] ??= 'emergency';
        $handler['deduplication_level'] ??= 'error';
        $handler['time'] = is_numeric($handler['time'] ?? null) ? (int) $handler['time'] : 60;
        $handler['store'] ??= null;
        $handler['factor'] = max(1, is_numeric($handler['factor'] ?? null) ? (int) $handler['factor'] : 1);
        $handler['message_type'] = is_numeric($handler['message_type'] ?? null) ? (int) $handler['message_type'] : 0;
        $handler['connection_string'] = $this->nullableString($handler['connection_string'] ?? null);
        $handler['timeout'] ??= null;
        $handler['connection_timeout'] ??= null;
        $handler['persistent'] = (bool) ($handler['persistent'] ?? false);
        $handler['to_email'] = $this->emailList($handler['to_email'] ?? []);
        $handler['from_email'] = $this->nullableString($handler['from_email'] ?? null);
        $handler['subject'] = $this->nullableString($handler['subject'] ?? null);
        $handler['headers'] = $this->stringList($handler['headers'] ?? []);
        $handler['webhook_url'] = $this->nullableString($handler['webhook_url'] ?? null);
        $handler['channel'] = $this->nullableString($handler['channel'] ?? null);
        $handler['bot_name'] ??= 'Monolog';
        $handler['use_attachment'] = (bool) ($handler['use_attachment'] ?? true);
        $handler['use_short_attachment'] = (bool) ($handler['use_short_attachment'] ?? false);
        $handler['include_extra'] = (bool) ($handler['include_extra'] ?? false);
        $handler['icon_emoji'] = $this->nullableString($handler['icon_emoji'] ?? null);
        $handler['exclude_fields'] = $this->stringList($handler['exclude_fields'] ?? []);
        $handler['console_formatter_options'] = is_array($handler['console_formatter_options'] ?? null)
            ? $handler['console_formatter_options']
            : [];
        $handler['verbosity_levels'] = is_array($handler['verbosity_levels'] ?? null)
            ? $handler['verbosity_levels']
            : [];
        $handler['interactive_only'] = (bool) ($handler['interactive_only'] ?? false);
        $handler['excluded_http_codes'] = $this->normalizeExcludedHttpCodes($handler['excluded_http_codes'] ?? []);

        $this->validateHandler($name, $handler);

        return $handler;
    }

    /** @param array<string, mixed> $handler */
    private function validateHandler(string $name, array $handler): void
    {
        if ($handler['type'] === 'service' && $this->serviceId($handler['id'] ?? null) === null) {
            throw new InvalidConfigurationException(sprintf('Monolog service handler "%s" needs an id.', $name));
        }

        if (
            in_array($handler['type'], ['fingers_crossed', 'buffer', 'filter', 'deduplication', 'sampling'], true)
            && $handler['handler'] === null
        ) {
            throw new InvalidConfigurationException(sprintf('Monolog handler "%s" needs a nested handler.', $name));
        }

        if (
            in_array($handler['type'], ['group', 'whatfailuregroup', 'fallbackgroup'], true)
            && $handler['members'] === []
        ) {
            throw new InvalidConfigurationException(sprintf('Monolog group handler "%s" needs members.', $name));
        }

        if ($handler['type'] === 'syslogudp' && $handler['host'] === null) {
            throw new InvalidConfigurationException(sprintf('Monolog syslogudp handler "%s" needs a host.', $name));
        }

        if ($handler['type'] === 'socket' && $handler['connection_string'] === null) {
            throw new InvalidConfigurationException(sprintf('Monolog socket handler "%s" needs a connection_string.', $name));
        }

        if (
            $handler['type'] === 'native_mailer'
            && ($handler['to_email'] === [] || $handler['from_email'] === null || $handler['subject'] === null)
        ) {
            throw new InvalidConfigurationException(
                sprintf('Monolog native_mailer handler "%s" needs to_email, from_email and subject.', $name),
            );
        }

        if ($handler['type'] === 'slackwebhook' && $handler['webhook_url'] === null) {
            throw new InvalidConfigurationException(sprintf('Monolog slackwebhook handler "%s" needs a webhook_url.', $name));
        }

        if ($handler['type'] !== 'filter' || $handler['accepted_levels'] === []) {
            return;
        }

        if (($handler['min_level'] ?? 'debug') !== 'debug' || ($handler['max_level'] ?? 'emergency') !== 'emergency') {
            throw new InvalidConfigurationException(
                sprintf('Monolog filter handler "%s" cannot combine accepted_levels with min_level/max_level.', $name),
            );
        }
    }

    /** @param array<string, mixed> $handler */
    private function buildHandler(ContainerBuilder $container, string $name, array $handler): string
    {
        $handlerId = $this->configuredHandlerId($name);

        if ($handler['type'] === 'service') {
            $container->setAlias($handlerId, $this->serviceId($handler['id']) ?? '');

            if ($handler['nested']) {
                $this->markNestedHandler($this->handlerId($name));
                $this->markNestedHandler($handlerId);
            }

            return $handlerId;
        }

        $handlerClass = $this->handlerClass($handler['type']);
        $definition = new Definition($handlerClass);

        if ($handler['include_stacktraces'] || $handler['base_path'] !== null) {
            $definition->setConfigurator([
                new Definition(FormatterConfigurator::class, [
                    $handler['include_stacktraces'],
                    $handler['base_path'],
                ]),
                '__invoke',
            ]);
        }

        $this->configureHandlerArguments($container, $handlerId, $definition, $handler);

        if ($handler['nested']) {
            $this->markNestedHandler($this->handlerId($name));
            $this->markNestedHandler($handlerId);
        }

        if ($handler['formatter'] !== null) {
            $definition->addMethodCall('setFormatter', [new Reference($handler['formatter'])]);
        }

        if ($this->psrLogProcessorEnabled($handler) && method_exists($handlerClass, 'pushProcessor')) {
            $definition->addMethodCall('pushProcessor', [
                new Reference($this->psrLogMessageProcessor($container, $handler['process_psr_3_messages'])),
            ]);
        }

        if (!in_array($handlerId, $this->nestedHandlers, true) && is_subclass_of($handlerClass, ResettableInterface::class)) {
            $definition->addTag('kernel.reset', ['method' => 'reset']);
        }

        $container->setDefinition($handlerId, $definition);

        return $handlerId;
    }

    /** @param array<string, mixed> $handler */
    private function configureHandlerArguments(
        ContainerBuilder $container,
        string $handlerId,
        Definition $definition,
        array $handler,
    ): void {

        switch ($handler['type']) {
            case 'stream':
                $definition->setArguments([
                    $handler['path'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['file_permission'],
                    $handler['use_locking'],
                ]);
                break;

            case 'rotating_file':
                $definition->setArguments([
                    $handler['path'],
                    $handler['max_files'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['file_permission'],
                    $handler['use_locking'],
                    $handler['date_format'],
                    $handler['filename_format'],
                ]);
                break;

            case 'fingers_crossed':
                $nestedHandlerId = $this->referencedHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);
                $activation = $this->activationStrategy($container, $handlerId, $handler);
                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $activation,
                    $handler['buffer_size'],
                    $handler['bubble'],
                    $handler['stop_buffering'],
                    $handler['passthru_level'],
                ]);
                break;

            case 'filter':
                $nestedHandlerId = $this->referencedHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);
                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $handler['accepted_levels'] !== [] ? $handler['accepted_levels'] : $handler['min_level'],
                    $handler['max_level'],
                    $handler['bubble'],
                ]);
                break;

            case 'buffer':
                $nestedHandlerId = $this->referencedHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);
                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $handler['buffer_size'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['flush_on_overflow'],
                ]);
                break;

            case 'deduplication':
                $nestedHandlerId = $this->referencedHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);
                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $handler['store'] ?? sprintf('%%kernel.cache_dir%%/monolog_dedup_%s', sha1($handlerId)),
                    $handler['deduplication_level'],
                    $handler['time'],
                    $handler['bubble'],
                ]);
                break;

            case 'group':
            case 'whatfailuregroup':
            case 'fallbackgroup':
                $definition->setArguments([
                    $this->handlerReferences($handler['members']),
                    $handler['bubble'],
                ]);
                break;

            case 'sampling':
                $nestedHandlerId = $this->referencedHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);
                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $handler['factor'],
                ]);
                break;

            case 'syslog':
                $definition->setArguments([
                    (string) $handler['ident'],
                    $handler['facility'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['logopts'],
                ]);
                break;

            case 'syslogudp':
                $definition->setArguments([
                    $handler['host'],
                    $handler['port'],
                    $handler['facility'],
                    $handler['level'],
                    $handler['bubble'],
                    (string) $handler['ident'],
                ]);
                break;

            case 'console':
                $definition->setArguments([
                    null,
                    $handler['bubble'],
                    $handler['verbosity_levels'],
                    $handler['console_formatter_options'],
                    $handler['interactive_only'],
                ]);
                break;

            case 'browser_console':
            case 'chromephp':
            case 'firephp':
            case 'test':
                $definition->setArguments([
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'null':
                $definition->setArguments([
                    $handler['level'],
                ]);
                break;

            case 'noop':
                $definition->setArguments([]);
                break;

            case 'error_log':
                $definition->setArguments([
                    $handler['message_type'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'native_mailer':
                $definition->setArguments([
                    $handler['to_email'],
                    $handler['subject'],
                    $handler['from_email'],
                    $handler['level'],
                    $handler['bubble'],
                ]);

                if ($handler['headers'] !== []) {
                    $definition->addMethodCall('addHeader', [$handler['headers']]);
                }
                break;

            case 'socket':
                $definition->setArguments([
                    $handler['connection_string'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['persistent'],
                    $handler['timeout'] ?? 0.0,
                    10.0,
                    $handler['connection_timeout'],
                ]);
                break;

            case 'slackwebhook':
                $definition->setArguments([
                    $handler['webhook_url'],
                    $handler['channel'],
                    $handler['bot_name'],
                    $handler['use_attachment'],
                    $handler['icon_emoji'],
                    $handler['use_short_attachment'],
                    $handler['include_extra'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['exclude_fields'],
                ]);
                break;
        }
    }

    /** @param array<string, mixed> $handler */
    private function activationStrategy(ContainerBuilder $container, string $handlerId, array $handler): Reference|string
    {
        if ($handler['activation_strategy'] !== null) {
            return new Reference($handler['activation_strategy']);
        }

        if ($handler['excluded_http_codes'] === []) {
            return (string) $handler['action_level'];
        }

        $innerId = sprintf('%s.activation_strategy', $handlerId);
        $container->setDefinition($innerId, new Definition(ErrorLevelActivationStrategy::class, [
            $handler['action_level'],
        ]));

        $strategyId = sprintf('%s.http_status_code_strategy', $handlerId);
        $container->setDefinition($strategyId, new Definition(HttpStatusCodeActivationStrategy::class, [
            $handler['excluded_http_codes'],
            new Reference($innerId),
        ]));

        return new Reference($strategyId);
    }

    private function handlerClass(string $type): string
    {
        return match ($type) {
            'stream' => StreamHandler::class,
            'rotating_file' => RotatingFileHandler::class,
            'fingers_crossed' => FingersCrossedHandler::class,
            'filter' => FilterHandler::class,
            'buffer' => BufferHandler::class,
            'deduplication' => DeduplicationHandler::class,
            'group' => GroupHandler::class,
            'whatfailuregroup' => WhatFailureGroupHandler::class,
            'fallbackgroup' => FallbackGroupHandler::class,
            'sampling' => SamplingHandler::class,
            'syslog' => SyslogHandler::class,
            'syslogudp' => SyslogUdpHandler::class,
            'console' => ConsoleHandler::class,
            'browser_console' => BrowserConsoleHandler::class,
            'chromephp' => ChromePHPHandler::class,
            'firephp' => FirePHPHandler::class,
            'null' => NullHandler::class,
            'noop' => NoopHandler::class,
            'test' => TestHandler::class,
            'error_log' => ErrorLogHandler::class,
            'native_mailer' => NativeMailerHandler::class,
            'socket' => SocketHandler::class,
            'slackwebhook' => SlackWebhookHandler::class,
            default => throw new InvalidConfigurationException(sprintf('Unsupported Monolog handler type "%s".', $type)),
        };
    }

    /**
     * @param list<string> $names
     * @return list<Reference>
     */
    private function handlerReferences(array $names): array
    {
        $references = [];

        foreach ($names as $name) {
            $handlerId = $this->referencedHandlerId($name);
            $this->markNestedHandler($handlerId);
            $references[] = new Reference($handlerId);
        }

        return $references;
    }

    /**
     * @param list<array{id: string, channels: mixed, priority: int, order: int}> $entries
     * @return array<string, mixed>
     */
    private function handlersToChannels(array $entries): array
    {
        usort(
            $entries,
            static function (array $left, array $right): int {
                if ($left['priority'] === $right['priority']) {
                    return $right['order'] <=> $left['order'];
                }

                return $left['priority'] <=> $right['priority'];
            },
        );

        $handlers = [];

        foreach ($entries as $entry) {
            if (in_array($entry['id'], $this->nestedHandlers, true)) {
                continue;
            }

            $handlers[$entry['id']] = $entry['channels'];
        }

        return $handlers;
    }

    private function handlerId(?string $name): string
    {
        $name ??= '';

        return str_starts_with($name, 'monolog.handler.')
            ? $name
            : sprintf('monolog.handler.%s', $name);
    }

    private function configuredHandlerId(string $name): string
    {
        return sprintf('monolog.configured_handler.%s', $this->shortHandlerName($name));
    }

    private function referencedHandlerId(?string $name): string
    {
        return $this->handlerId($name);
    }

    private function shortHandlerName(string $name): string
    {
        return str_starts_with($name, 'monolog.handler.')
            ? substr($name, strlen('monolog.handler.'))
            : $name;
    }

    private function markNestedHandler(string $handlerId): void
    {
        if (in_array($handlerId, $this->nestedHandlers, true)) {
            return;
        }

        $this->nestedHandlers[] = $handlerId;
    }

    /** @param array<string, mixed> $handler */
    private function psrLogProcessorEnabled(array $handler): bool
    {
        $processor = $handler['process_psr_3_messages'];

        if (($processor['enabled'] ?? null) !== null) {
            return (bool) $processor['enabled'];
        }

        return $handler['handler'] === null && $handler['members'] === [];
    }

    /** @param array<string, mixed> $processorOptions */
    private function psrLogMessageProcessor(ContainerBuilder $container, array $processorOptions): string
    {
        $arguments = [];

        if (($processorOptions['date_format'] ?? null) !== null || ($processorOptions['remove_used_context_fields'] ?? false)) {
            $arguments = [
                $processorOptions['date_format'] ?? null,
                (bool) ($processorOptions['remove_used_context_fields'] ?? false),
            ];
        }

        $processorId = 'monolog.processor.psr_log_message';

        if ($arguments !== []) {
            $processorId .= '.' . ContainerBuilder::hash($arguments);
        }

        if (!$container->hasDefinition($processorId)) {
            $container->setDefinition($processorId, new Definition(PsrLogMessageProcessor::class, $arguments));
        }

        return $processorId;
    }

    private function filePermission(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && str_starts_with($value, '0')) {
            return (int) octdec($value);
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if ($value === null || $value === false || $value === '') {
            return [];
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return [(string) $value];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_scalar($item) && !$item instanceof \Stringable) {
                continue;
            }

            $string = trim((string) $item);

            if ($string === '') {
                continue;
            }

            $strings[] = $string;
        }

        return $strings;
    }

    /** @return list<string> */
    private function emailList(mixed $value): array
    {
        return $this->stringList($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function serviceId(mixed $value): ?string
    {
        $id = $this->nullableString($value);

        if ($id === null) {
            return null;
        }

        return ltrim($id, '@');
    }

    /** @return array{enabled: bool|null, date_format?: string|null, remove_used_context_fields?: bool} */
    private function normalizePsrLogProcessor(mixed $value): array
    {
        if (is_bool($value)) {
            return ['enabled' => $value];
        }

        if (!is_array($value)) {
            return ['enabled' => null];
        }

        return [
            'enabled'                    => array_key_exists('enabled', $value) ? (bool) $value['enabled'] : null,
            'date_format'                => $this->nullableString($value['date_format'] ?? null),
            'remove_used_context_fields' => (bool) ($value['remove_used_context_fields'] ?? false),
        ];
    }

    private function normalizeChannelConfiguration(mixed $channels): mixed
    {
        if ($channels === null || $channels === false || $channels === []) {
            return null;
        }

        if (is_scalar($channels) || $channels instanceof \Stringable) {
            $channels = [(string) $channels];
        }

        if (!is_array($channels)) {
            return null;
        }

        $type = is_string($channels['type'] ?? null) ? $channels['type'] : null;
        $elements = array_key_exists('elements', $channels)
            ? $this->stringList($channels['elements'])
            : $this->stringList($channels);

        if ($elements === []) {
            return null;
        }

        $exclusive = $type === 'exclusive';
        $inclusive = $type === 'inclusive';
        $normalized = [];

        foreach ($elements as $element) {
            if (str_starts_with($element, '!')) {
                if ($inclusive) {
                    throw new InvalidConfigurationException('Cannot combine inclusive and exclusive Monolog channels.');
                }

                $exclusive = true;
                $normalized[] = substr($element, 1);
                continue;
            }

            if ($exclusive) {
                throw new InvalidConfigurationException('Cannot combine inclusive and exclusive Monolog channels.');
            }

            $inclusive = true;
            $normalized[] = $element;
        }

        return [
            'type'     => $exclusive ? 'exclusive' : 'inclusive',
            'elements' => array_values(array_unique($normalized)),
        ];
    }

    /** @return list<array{code: int, urls: list<string>}> */
    private function normalizeExcludedHttpCodes(mixed $value): array
    {
        if ($value === null || $value === false || $value === []) {
            return [];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $codes = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $code = $item['code'] ?? (is_int($key) ? array_key_first($item) : $key);
                $urls = $item['urls'] ?? (is_array($item[$code] ?? null) ? $item[$code] : []);
            } else {
                $code = $item;
                $urls = [];
            }

            if (!is_numeric($code)) {
                continue;
            }

            $codes[] = [
                'code' => (int) $code,
                'urls' => $this->stringList($urls),
            ];
        }

        return $codes;
    }
}
