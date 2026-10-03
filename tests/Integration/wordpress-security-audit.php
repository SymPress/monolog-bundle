<?php

declare(strict_types=1);

// Run only against a disposable WordPress installation and database.
// php tests/Integration/wordpress-security-audit.php /absolute/disposable/wp
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$wordpress = $argv[1] ?? '';
if (!is_file($wordpress . '/wp-load.php')) {
    throw new RuntimeException('Supply the path to a disposable installed WordPress fixture.');
}
require $wordpress . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
// A disposable fixture must never deliver user/password-change email.
add_filter('pre_wp_mail', static fn (): bool => true);

use SymPress\Kernel\Hook\HookCompilerPass;
use SymPress\Kernel\Hook\HookLoader;
use SymPress\MonologBundle\MonologBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

$project = sys_get_temp_dir() . '/wordpress-security-audit-' . bin2hex(random_bytes(5));
$container = new ContainerBuilder();
foreach (['project_dir' => $project, 'environment' => 'production', 'debug' => false, 'cache_dir' => $project . '/cache', 'logs_dir' => $project . '/log'] as $name => $value) {
    $container->setParameter('kernel.' . $name, $value);
}
(new MonologBundle())->build($container);
$container->register(HookLoader::class, HookLoader::class)->setPublic(true);
$container->addCompilerPass(new HookCompilerPass());
(new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/Resources/config')))->load('services.yaml');
$container->compile();
$container->get(HookLoader::class)->register();

$admin = get_user_by('login', 'audit-admin');
if (!($admin instanceof WP_User)) {
    throw new RuntimeException('Fixture must have audit-admin installed as administrator.');
}
wp_set_current_user($admin->ID);
$existing = get_user_by('login', 'loginUsernameSentinel');
$userData = ['user_login' => 'loginUsernameSentinel', 'user_pass' => 'passwordSentinel', 'user_email' => 'audit-user@example.test', 'role' => 'subscriber'];
if ($existing instanceof WP_User) {
    $userData['ID'] = $existing->ID;
}
$userId = $existing instanceof WP_User ? wp_update_user($userData) : wp_insert_user($userData);
if (is_wp_error($userId)) {
    throw new RuntimeException('Cannot create disposable test user.');
}
$user = get_user_by('id', $userId);
$user->set_role('editor');
$user->add_role('author');
$user->remove_role('author');

wp_set_current_user(0);
$failed = wp_signon(['user_login' => 'guessedUsernameSentinel', 'user_password' => 'guessedPasswordSentinel'], false);
if (!is_wp_error($failed)) {
    throw new RuntimeException('Invalid login must fail.');
}
$success = wp_signon(['user_login' => 'loginUsernameSentinel', 'user_password' => 'passwordSentinel'], false);
if (!($success instanceof WP_User)) {
    throw new RuntimeException('Confirmed fixture login must succeed.');
}
wp_set_current_user($admin->ID);

$plugin = WP_PLUGIN_DIR . '/audit-fixture/audit.php';
if (!is_dir(dirname($plugin))) {
    mkdir(dirname($plugin), 0755, true);
}
file_put_contents($plugin, "<?php\n/* Plugin Name: Disposable audit fixture */\n");
$result = activate_plugin('audit-fixture/audit.php');
if (is_wp_error($result)) {
    throw new RuntimeException('Disposable plugin activation failed.');
}
deactivate_plugins('audit-fixture/audit.php');
foreach (['audit-a', 'audit-b'] as $theme) {
    $directory = WP_CONTENT_DIR . '/themes/' . $theme;
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    file_put_contents($directory . '/style.css', "/* Theme Name: $theme */\n");
    file_put_contents($directory . '/index.php', '<?php');
}
register_theme_directory(WP_CONTENT_DIR . '/themes');
wp_clean_themes_cache();
switch_theme('audit-a');
switch_theme('audit-b');
update_option('siteurl', 'https://oldSiteurlOptionSentinel.example.test');
update_option('siteurl', 'https://newSiteurlOptionSentinel.example.test');
update_option('admin_email', 'oldEmailOptionSentinel@example.test');
update_option('admin_email', 'newEmailOptionSentinel@example.test');
update_option('custom_secret_option', 'optionPasswordSentinel');

$files = glob($project . '/log/security-*.log') ?: [];
if (count($files) !== 1 || (fileperms($files[0]) & 07777) !== 0600) {
    throw new RuntimeException('Audit must produce a private security log in production.');
}
$log = (string) file_get_contents($files[0]);
foreach (['login.succeeded', 'login.failed', 'user.role_set', 'user.role_added', 'user.role_removed', 'plugin.activated', 'plugin.deactivated', 'theme.switched', 'option.updated', '"user_id":' . $userId, '"theme":"audit-b"', '"previous_theme":"audit-a"'] as $expected) {
    if (!str_contains($log, $expected)) {
        throw new RuntimeException('Expected WordPress event/field is absent: ' . $expected);
    }
}
foreach (['Sentinel', 'custom_secret_option', '"password"', '"username"', '"ip"'] as $forbidden) {
    if (str_contains($log, $forbidden)) {
        throw new RuntimeException('Sensitive or non-audited WordPress input leaked.');
    }
}
$main = glob($project . '/log/production-*.log') ?: [];
if (count($main) !== 1 || str_contains((string) file_get_contents($main[0]), 'login.succeeded')) {
    throw new RuntimeException('Production main warning threshold must suppress successful login info.');
}
echo json_encode(['wordpress' => $wp_version, 'php' => PHP_VERSION, 'events' => substr_count($log, 'WordPress security audit:'), 'private_security_info' => true, 'production_main_warning' => true, 'sentinels_absent' => true], JSON_THROW_ON_ERROR) . "\n";
