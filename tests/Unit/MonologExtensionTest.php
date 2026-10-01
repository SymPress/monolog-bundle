<?php

declare(strict_types=1);

namespace SymPress\MonologBundle\Tests\Unit;

use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\SiteKernel;
use SymPress\Kernel\WpContext;
use SymPress\MonologBundle\Handler\ConsoleHandler;
use SymPress\MonologBundle\MonologBundle;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class MonologExtensionTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    protected function tearDown(): void
    {
        if ($this->paths === []) {
            return;
        }

        (new Filesystem())->remove($this->paths);
        $this->paths = [];
    }

    public function testConfiguredHandlerOutputRedactsUrlsInterpolationSqlAndErrors(): void
    {
        $project = $this->tmpPath('redaction-output');
        $file = $project . '/var/log/sensitive.log';
        $container = $this->compileContainer($project, ['handlers' => ['main' => ['type' => 'stream', 'path' => $file, 'level' => 'debug']]]);
        $logger = $container->get('logger');
        $logger->warning('Fetch {url} token=messageSentinel', [
            'url' => 'https://user:passwordSentinel@example.test/path?token=urlSentinel&customer=valueSentinel',
            'query' => "SELECT * FROM accounts WHERE secret='sqlSentinel'",
            'error' => 'server error errorSentinel',
            'exception' => new \RuntimeException('exceptionSentinel'),
            'api_key' => 'keySentinel',
        ]);
        foreach (['redis://:redisPasswordSentinel@cache.example.test', 'mysql://databaseUserSentinel:@database.example.test', 'smtp://smtpUserSentinel@mail.example.test', 'redis://:%65ncodedPasswordSentinel@cache.example.test'] as $dsn) {
            $logger->warning('Connection failed: ' . $dsn);
        }
        $contents = (string) file_get_contents($file);
        foreach (['redisPasswordSentinel', 'databaseUserSentinel', 'smtpUserSentinel', 'ncodedPasswordSentinel'] as $sentinel) {
            self::assertStringNotContainsString($sentinel, $contents);
        }
        foreach (['passwordSentinel', 'messageSentinel', 'urlSentinel', 'valueSentinel', 'sqlSentinel', 'errorSentinel', 'exceptionSentinel', 'keySentinel'] as $sentinel) {
            self::assertStringNotContainsString($sentinel, $contents);
        }
        self::assertStringContainsString('example.test/path', $contents);
    }

    public function testProductionDefaultsRotateAndSuppressDebugWithoutProfilerCollection(): void
    {
        $project = $this->tmpPath('production-defaults');
        $handler = \SymPress\MonologBundle\Handler\DefaultHandlerFactory::create($project, 'production', 'auto', $project . '/app.log');
        self::assertInstanceOf(RotatingFileHandler::class, $handler);
        $logger = new Logger('prod', [$handler]);
        $logger->debug('suppressed');
        $logger->warning('visible');
        $files = glob($project . '/app-*.log') ?: [];
        self::assertCount(1, $files);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        self::assertStringNotContainsString('suppressed', (string) file_get_contents($files[0]));
        self::assertStringContainsString('visible', (string) file_get_contents($files[0]));
    }

    public function testOpaqueConfiguredServiceIsRedactedWithoutChangingItsTypedService(): void
    {
        $project = $this->tmpPath('opaque-output');
        (new Filesystem())->mkdir($project);
        $file = $project . '/opaque.log';
        $container = $this->compileContainer($project, ['handlers' => [
            'main' => ['type' => 'service', 'id' => OpaqueFileHandler::class],
        ]], $file);
        self::assertInstanceOf(OpaqueFileHandler::class, $container->get(OpaqueFileHandler::class));
        $container->get('logger')->warning('Bearer bearerSentinel', [
            'password' => 'opaqueSentinel', 'sql' => 'SELECT opaqueSqlSentinel',
        ]);
        $output = (string) file_get_contents($file);
        foreach (['bearerSentinel', 'opaqueSentinel', 'opaqueSqlSentinel'] as $value) {
            self::assertStringNotContainsString($value, $output);
        }
    }

    public function testDefaultRotationRetainsFourteenFilesAndDisabledLogsDiscardRecords(): void
    {
        $project = $this->tmpPath('retention-output');
        (new Filesystem())->mkdir($project);
        for ($day = 1; $day <= 20; ++$day) {
            file_put_contents($project . '/app-' . date('Y-m-d', strtotime('-' . $day . ' days')) . '.log', 'old');
        }
        $handler = \SymPress\MonologBundle\Handler\DefaultHandlerFactory::create($project, 'production', 'auto', $project . '/app.log');
        (new Logger('rotation', [$handler]))->warning('current');
        $handler->close();
        self::assertCount(14, glob($project . '/app-*.log') ?: []);
        $disabled = \SymPress\MonologBundle\Handler\DefaultHandlerFactory::create(false, 'production', 'auto', $project . '/disabled.log');
        (new Logger('disabled', [$disabled]))->warning('discarded');
        self::assertFileDoesNotExist($project . '/disabled.log');
    }

    public function testSymfonyStyleHandlerConfigurationWritesThroughFingersCrossed(): void
    {
        $projectDir = $this->tmpPath('monolog-project');
        $logFile = sprintf('%s/var/log/fingers.log', $projectDir);
        $container = $this->compileContainer($projectDir, [
            'channels' => ['security'],
            'handlers' => [
                'main' => [
                    'type' => 'fingers_crossed',
                    'action_level' => 'error',
                    'handler' => 'nested',
                    'buffer_size' => 10,
                ],
                'nested' => [
                    'type' => 'stream',
                    'path' => $logFile,
                    'level' => 'debug',
                    'nested' => true,
                ],
                'rotating' => [
                    'type' => 'rotating_file',
                    'path' => sprintf('%s/var/log/security.log', $projectDir),
                    'max_files' => 5,
                    'level' => 'info',
                    'channels' => ['security'],
                    'priority' => 20,
                ],
            ],
        ]);

        $logger = $container->get('logger');
        $securityLogger = $container->get('monolog.logger.security');

        self::assertInstanceOf(Logger::class, $logger);
        self::assertInstanceOf(Logger::class, $securityLogger);
        self::assertContains(FingersCrossedHandler::class, $this->handlerClasses($logger));
        self::assertContains(RotatingFileHandler::class, $this->handlerClasses($securityLogger));

        $logger->debug('Debug before error');
        $logger->error('Failure {id}', ['id' => 123]);

        self::assertFileExists($logFile);
        self::assertStringContainsString('Debug before error', (string) file_get_contents($logFile));
        self::assertStringContainsString('Failure 123', (string) file_get_contents($logFile));
    }

    public function testExclusiveChannelsAndDisabledDefaultHandlersAreApplied(): void
    {
        $projectDir = $this->tmpPath('monolog-project');
        $logFile = sprintf('%s/var/log/app.log', $projectDir);
        $container = $this->compileContainer($projectDir, [
            'handlers' => [
                'main' => [
                    'type' => 'stream',
                    'enabled' => false,
                ],
                'file' => [
                    'type' => 'stream',
                    'path' => $logFile,
                    'level' => 'debug',
                    'channels' => ['!database'],
                ],
            ],
        ]);

        $logger = $container->get('logger');
        $databaseLogger = $container->get('monolog.logger.database');

        self::assertInstanceOf(Logger::class, $logger);
        self::assertInstanceOf(Logger::class, $databaseLogger);

        $logger->warning('Visible app log');
        $databaseLogger->warning('Hidden database log');

        $contents = (string) file_get_contents($logFile);

        self::assertStringContainsString('Visible app log', $contents);
        self::assertStringNotContainsString('Hidden database log', $contents);
    }

    public function testPsr3MessageProcessingCanBeDisabledPerHandler(): void
    {
        $projectDir = $this->tmpPath('monolog-project');
        $logFile = sprintf('%s/var/log/raw.log', $projectDir);
        $container = $this->compileContainer($projectDir, [
            'handlers' => [
                'main' => [
                    'type' => 'stream',
                    'path' => $logFile,
                    'process_psr_3_messages' => false,
                ],
            ],
        ]);

        $logger = $container->get('logger');

        self::assertInstanceOf(Logger::class, $logger);

        $logger->info('Hello {name}', ['name' => 'Ada']);

        self::assertStringContainsString('Hello {name}', (string) file_get_contents($logFile));
    }

    public function testDocumentedHandlerTypesCompileFromTheirMinimumConfiguration(): void
    {
        $handlers = [
            'sink' => ['type' => 'null', 'nested' => true],
            'stream' => ['type' => 'stream'],
            'rotating' => ['type' => 'rotating_file'],
            'fingers' => ['type' => 'fingers_crossed', 'handler' => 'sink'],
            'filter' => ['type' => 'filter', 'handler' => 'sink'],
            'buffer' => ['type' => 'buffer', 'handler' => 'sink'],
            'deduplication' => ['type' => 'deduplication', 'handler' => 'sink'],
            'sampling' => ['type' => 'sampling', 'handler' => 'sink'],
            'group' => ['type' => 'group', 'members' => ['sink']],
            'whatfailuregroup' => ['type' => 'whatfailuregroup', 'members' => ['sink']],
            'fallbackgroup' => ['type' => 'fallbackgroup', 'members' => ['sink']],
            'service' => ['type' => 'service', 'id' => 'monolog.handler.main'],
            'syslog' => ['type' => 'syslog'],
            'syslogudp' => ['type' => 'syslogudp', 'host' => '127.0.0.1'],
            'console' => ['type' => 'console'],
            'browser_console' => ['type' => 'browser_console'],
            'chromephp' => ['type' => 'chromephp'],
            'firephp' => ['type' => 'firephp'],
            'test' => ['type' => 'test'],
            'noop' => ['type' => 'noop'],
            'error_log' => ['type' => 'error_log'],
            'native_mailer' => [
                'type' => 'native_mailer',
                'to_email' => 'ops@example.test',
                'from_email' => 'wordpress@example.test',
                'subject' => 'WordPress log',
            ],
            'socket' => ['type' => 'socket', 'connection_string' => 'tcp://127.0.0.1:9999'],
            'slackwebhook' => ['type' => 'slackwebhook', 'webhook_url' => 'https://example.test/hook'],
        ];

        $container = $this->compileContainer($this->tmpPath('monolog-handlers-project'), [
            'handlers' => $handlers,
        ]);

        foreach (array_keys($handlers) as $name) {
            if ($name === 'service') {
                continue;
            }

            self::assertTrue(
                $container->hasDefinition('monolog.configured_handler.' . $name)
                || $container->hasAlias('monolog.configured_handler.' . $name),
                sprintf('Handler type for "%s" was not compiled.', $name),
            );
        }
    }

    public function testKernelRuntimeContainerKeepsMonologExtensionConfiguration(): void
    {
        $projectDir = $this->tmpPath('monolog-runtime-project');
        $siteConfigDir = sprintf('%s/config/packages', $projectDir);
        (new Filesystem())->mkdir($siteConfigDir);
        file_put_contents($siteConfigDir . '/monolog.yaml', Yaml::dump([
            'monolog' => [
                'channels' => ['security'],
                'handlers' => [
                    'security_file' => [
                        'type' => 'stream',
                        'path' => '%kernel.logs_dir%/security.log',
                        'channels' => ['security'],
                    ],
                ],
            ],
        ], 6, 4));

        $bundlePath = dirname(__DIR__, 2);
        $registry = (new BundleRegistry())->add(new BundleMetadata(
            'sympress/monolog-bundle',
            'wordpress-muplugin',
            'monolog-bundle/monolog-bundle.php',
            $bundlePath,
            $bundlePath . '/composer.json',
            new MonologBundle(),
        ));
        $kernel = new SiteKernel($projectDir, 'development', true, null, WpContext::new()->force(WpContext::CORE));
        $container = $kernel->createContainer();
        $loadedConfigFiles = $kernel->configureContainer($container->builder(), $container, $registry);

        $kernel->createRuntimeContainer($container, $registry, $loadedConfigFiles);
        $securityLogger = $container->get('monolog.logger.security');

        self::assertInstanceOf(Logger::class, $securityLogger);

        $securityLogger->warning('Runtime security log');

        $logFile = sprintf('%s/var/log/security.log', $projectDir);

        self::assertFileExists($logFile);
        self::assertStringContainsString('Runtime security log', (string) file_get_contents($logFile));
    }

    public function testConsoleHandlerHonorsShellVerbosity(): void
    {
        $projectDir = $this->tmpPath('monolog-console-project');
        $logFile = sprintf('%s/var/log/console.log', $projectDir);
        (new Filesystem())->mkdir(dirname($logFile));
        $previousVerbosity = getenv('SHELL_VERBOSITY');
        putenv('SHELL_VERBOSITY=2');

        try {
            $logger = new Logger('console');
            $logger->pushHandler(new ConsoleHandler($logFile));
            $logger->debug('Hidden debug');
            $logger->info('Visible info');
        } finally {
            if ($previousVerbosity === false) {
                putenv('SHELL_VERBOSITY');
            } else {
                putenv('SHELL_VERBOSITY=' . $previousVerbosity);
            }
        }

        $contents = (string) file_get_contents($logFile);

        self::assertStringNotContainsString('Hidden debug', $contents);
        self::assertStringContainsString('Visible info', $contents);
    }

    /**
     * @param array<string, mixed> $monologConfig
     */
    private function compileContainer(string $projectDir, array $monologConfig, ?string $opaqueFile = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sprintf('%s/var/cache/test/kernel', $projectDir));
        $container->setParameter('kernel.logs_dir', sprintf('%s/var/log', $projectDir));

        (new MonologBundle())->build($container);
        if ($opaqueFile !== null) {
            $container->register(OpaqueFileHandler::class, OpaqueFileHandler::class)->setArguments([$opaqueFile])->setPublic(true);
        }

        $configDir = dirname(__DIR__, 2) . '/Resources/config';
        (new YamlFileLoader($container, new FileLocator($configDir), 'test'))->load('services.yaml');

        $siteConfigDir = sprintf('%s/config/packages', $projectDir);
        (new Filesystem())->mkdir($siteConfigDir);
        $configFile = sprintf('%s/monolog.yaml', $siteConfigDir);
        file_put_contents($configFile, Yaml::dump(['monolog' => $monologConfig], 6, 4));

        (new YamlFileLoader($container, new FileLocator($siteConfigDir), 'test'))->load('monolog.yaml');
        $container->compile();

        return $container;
    }

    private function tmpPath(string $prefix): string
    {
        $path = sprintf('%s/%s-%s', sys_get_temp_dir(), $prefix, uniqid('', true));
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @return list<class-string>
     */
    private function handlerClasses(Logger $logger): array
    {
        return array_map(static fn (object $handler): string => $handler::class, $logger->getHandlers());
    }
}

final readonly class OpaqueFileHandler implements \Monolog\Handler\HandlerInterface
{
    public function __construct(private string $file) {}
    public function isHandling(\Monolog\LogRecord $record): bool { return true; }
    public function handle(\Monolog\LogRecord $record): bool
    {
        file_put_contents($this->file, json_encode($record->toArray(), JSON_THROW_ON_ERROR));
        return false;
    }
    public function handleBatch(array $records): void { foreach ($records as $record) { $this->handle($record); } }
    public function close(): void {}
}
