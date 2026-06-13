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
    private function compileContainer(string $projectDir, array $monologConfig): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sprintf('%s/var/cache/test/kernel', $projectDir));
        $container->setParameter('kernel.logs_dir', sprintf('%s/var/log', $projectDir));

        (new MonologBundle())->build($container);

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
