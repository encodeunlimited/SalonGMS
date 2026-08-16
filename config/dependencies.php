<?php

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        'settings' => function () {
            return require __DIR__ . '/settings.php';
        },

        PDO::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['db'];
            
            if (($settings['connection'] ?? 'mysql') === 'sqlite') {
                // SQLite Connection
                // Handle PHAR execution - SQLite needs a real file path
                if (str_starts_with(__DIR__, 'phar://')) {
                    $pharPath = \Phar::running(false);
                    $baseDir = dirname($pharPath);
                    $dataDir = $baseDir . '/data';
                    $dbPath = $dataDir . '/database.sqlite';
                    
                    if (!is_dir($dataDir)) {
                        mkdir($dataDir, 0777, true);
                    }
                    
                    // If the DB doesn't exist outside, extract it from the PHAR
                    if (!file_exists($dbPath)) {
                        $internalDbPath = __DIR__ . '/../data/database.sqlite';
                        if (file_exists($internalDbPath)) {
                            copy($internalDbPath, $dbPath);
                        }
                    }
                } else {
                    $dbPath = __DIR__ . '/../data/database.sqlite';
                }
                
                $pdo = new PDO("sqlite:$dbPath");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5); // 5 second timeout
                // High-performance SQLite PRAGMAs
                $pdo->exec('PRAGMA journal_mode = WAL;');
                $pdo->exec('PRAGMA synchronous = NORMAL;');
                $pdo->exec('PRAGMA busy_timeout = 5000;');
                $pdo->exec('PRAGMA cache_size = -64000;'); // 64MB cache
                $pdo->exec('PRAGMA temp_store = MEMORY;');
                // Enable foreign keys
                $pdo->exec('PRAGMA foreign_keys = ON;');
                return $pdo;
            }

            // MySQL Connection
            $host = $settings['host'];
            $dbname = $settings['dbname'];
            $port = $settings['port'];
            
            $dsn = "mysql:host=$host;dbname=$dbname;port=$port;charset=utf8mb4";
            return new PDO($dsn, $settings['user'], $settings['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        },

        \App\Repositories\BookingTypeRepository::class => function (ContainerInterface $c) {
            return new \App\Repositories\BookingTypeRepository($c->get(PDO::class));
        },
        \App\Repositories\NotificationRepository::class => function (ContainerInterface $c) {
            return new \App\Repositories\NotificationRepository($c->get(PDO::class));
        },
        \App\Web\Controllers\NotificationController::class => function (ContainerInterface $c) {
            return new \App\Web\Controllers\NotificationController(
                $c->get(\Slim\Views\Twig::class),
                $c->get(\App\Repositories\NotificationRepository::class)
            );
        },
        \App\Web\Controllers\BirthdayController::class => function (ContainerInterface $c) {
            return new \App\Web\Controllers\BirthdayController(
                $c->get(\Slim\Views\Twig::class),
                $c->get(\App\Repositories\CustomerRepository::class),
                $c->get(\App\Repositories\PackageRepository::class)
            );
        },
        \App\Services\LoyaltyService::class => function (ContainerInterface $c) {
            return new \App\Services\LoyaltyService(
                $c->get(PDO::class),
                $c->get(\App\Repositories\CustomerRepository::class)
            );
        },

        \App\Web\Controllers\CustomerController::class => function (ContainerInterface $c) {
            return new \App\Web\Controllers\CustomerController(
                $c->get(\Slim\Views\Twig::class),
                $c->get(\App\Repositories\CustomerRepository::class),
                $c->get(\App\Repositories\AppointmentRepository::class),
                $c->get(\App\Repositories\InvoiceRepository::class),
                $c->get(\App\Services\LoyaltyService::class)
            );
        },

        \App\Services\InvoiceService::class => function (ContainerInterface $c) {
            return new \App\Services\InvoiceService(
                $c->get(\App\Repositories\InvoiceRepository::class),
                $c->get(\App\Repositories\InvoiceItemRepository::class),
                $c->get(\App\Repositories\CommissionRepository::class),
                $c->get(\App\Repositories\UserRepository::class),
                $c->get(\App\Repositories\AppointmentRepository::class),
                $c->get(\App\Services\LoyaltyService::class)
            );
        },
        \App\Repositories\PaymentTypeRepository::class => function (ContainerInterface $c) {
            return new \App\Repositories\PaymentTypeRepository($c->get(PDO::class));
        },
        \App\Repositories\ServiceCategoryRepository::class => function (ContainerInterface $c) {
            return new \App\Repositories\ServiceCategoryRepository($c->get(PDO::class));
        },
        \App\Repositories\CommissionRepository::class => function (ContainerInterface $c) {
            return new \App\Repositories\CommissionRepository($c->get(PDO::class));
        },

        Twig::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');
            
            // Handle PHAR execution for Twig cache
            if (str_starts_with(__DIR__, 'phar://')) {
                $baseDir = dirname(\Phar::running(false));
                $cacheDir = $baseDir . '/data/cache/twig';
            } else {
                $cacheDir = __DIR__ . '/../data/cache/twig';
            }
            
            // Use Twig caching if not in development mode
            $cache = $settings['displayErrorDetails'] ? false : $cacheDir;
            return Twig::create(__DIR__ . '/../templates', ['cache' => $cache]);
        },

        \App\Services\PdfService::class => function (ContainerInterface $c) {
            return new \App\Services\PdfService($c->get(Twig::class));
        },
        
        \App\Services\WhatsAppService::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['whatsapp'] ?? [];
            return new \App\Services\WhatsAppService(
                $settings['provider_url'] ?? '',
                $settings['instance_id'] ?? '',
                $settings['token'] ?? ''
            );
        },

        PDO::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['db'];
            $connection = $settings['connection'];
            
            if ($connection === 'sqlite') {
                $dbPath = __DIR__ . '/../' . $settings['database'];
                // Ensure data directory exists
                if (!file_exists(dirname($dbPath))) {
                    mkdir(dirname($dbPath), 0755, true);
                }
                $dsn = "sqlite:" . $dbPath;
                $pdo = new PDO($dsn);
            } else {
                // MySQL / PostgreSQL
                $dsn = "$connection:host={$settings['host']};port={$settings['port']};dbname={$settings['database']};charset=utf8mb4";
                
                $options = [
                    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                ];
                if (!empty($settings['ssl_ca'])) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $settings['ssl_ca'];
                }
                
                $pdo = new PDO($dsn, $settings['username'], $settings['password'], $options);
            }
            
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            return $pdo;
        },

        \App\Web\Controllers\Portal\DashboardController::class => function (ContainerInterface $c) {
            return new \App\Web\Controllers\Portal\DashboardController(
                $c->get(\Slim\Views\Twig::class),
                $c->get(\App\Repositories\AppointmentRepository::class),
                $c->get(\App\Repositories\CustomerRepository::class),
                $c->get(\App\Services\LoyaltyService::class)
            );
        },
    ]);
};
