<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

// The behaviour of the parsers is stored in parser_config, not in this file.
// The settings service below is a singleton, so a command reads the table
// once and every factory asks it for the numbers it needs.
$parserTunables = static fn (): array =>
    \Yii::createObject(\app\shared\Forum\Service\ParserSettingsService::class)->tunables();

$config = [
    'id' => 'basic-console',
    'name' => 'TRVL',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
        '@tests' => '@app/tests',
    ],
    'components' => [
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
        'log' => [
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                    // Yii adds a dump of the superglobals to every export, and in
                    // a CLI process $_SERVER is the whole container environment:
                    // the bot token and the database and forum passwords end up
                    // in app.log. The failure reasons are in the messages.
                    'logVars' => [],
                ],
            ],
        ],
        'db' => $db,
    ],
    'controllerMap' => [
        'forum-parser' => [
            'class' => \app\commands\ForumParserController::class,
        ],
        'forum-post-parser' => [
            'class' => \app\commands\ForumPostParserController::class,
        ],
        'gallery-parser' => [
            'class' => \app\commands\GalleryParserController::class,
        ],
        'member-parser' => [
            'class' => \app\commands\MemberParserController::class,
        ],
        'telegram' => [
            'class' => \app\commands\TelegramController::class,
        ],
    ],
    'container' => [
        'singletons' => [
            \app\shared\Settings\Service\PublicationSettingsService::class => static fn (): \app\shared\Settings\Service\PublicationSettingsService =>
                new \app\shared\Settings\Service\PublicationSettingsService(
                    \Yii::createObject(\app\shared\Settings\Contract\PublicationSettingsRepositoryInterface::class),
                    (string)(getenv('TELEGRAM_PUBLISH_CRON_SCHEDULE') ?: ''),
                ),
            \app\shared\Forum\Service\ParserSettingsService::class => static fn (): \app\shared\Forum\Service\ParserSettingsService =>
                new \app\shared\Forum\Service\ParserSettingsService(
                    new \app\shared\Forum\Infrastructure\ForumRepository(\Yii::$app->getDb()),
                ),
        ],
        'definitions' => [
            \app\shared\Settings\Contract\PublicationSettingsRepositoryInterface::class => static fn (): \app\shared\Settings\Infrastructure\PublicationSettingsRepository =>
                new \app\shared\Settings\Infrastructure\PublicationSettingsRepository(\Yii::$app->getDb()),
            \Psr\Log\LoggerInterface::class => static fn (): \app\shared\Forum\Infrastructure\YiiPsrLoggerAdapter =>
                new \app\shared\Forum\Infrastructure\YiiPsrLoggerAdapter(\Yii::$app->getLog()->getLogger()),
            \app\shared\Telegram\Contract\TelegramChannelClientInterface::class => static function (): ?\app\shared\Telegram\Infrastructure\NutgramChannelClient {
                $token = (string)(getenv('TELEGRAM_BOT_TOKEN') ?: '');
                return $token === '' ? null : new \app\shared\Telegram\Infrastructure\NutgramChannelClient($token);
            },
            \app\shared\Telegram\Service\ChannelService::class => static fn (): \app\shared\Telegram\Service\ChannelService =>
                new \app\shared\Telegram\Service\ChannelService(
                    \Yii::createObject(\app\shared\Telegram\Contract\TelegramChannelClientInterface::class),
                    (string)(getenv('TELEGRAM_CHANNEL_ID') ?: '@gsu_travels'),
                    new \app\shared\Telegram\Infrastructure\PublishedDescriptionRepository(\Yii::$app->getDb()),
                ),
            \app\shared\Publications\Service\PublicationsService::class => static fn (): \app\shared\Publications\Service\PublicationsService =>
                new \app\shared\Publications\Service\PublicationsService(
                    new \app\shared\Publications\Infrastructure\PublicationRepository(\Yii::$app->getDb()),
                    new \app\shared\Forum\Infrastructure\YiiPsrLoggerAdapter(\Yii::$app->getLog()->getLogger()),
                    \Yii::createObject(\app\shared\Telegram\Service\ChannelService::class),
                    \Yii::createObject(\app\shared\Forum\Contract\ForumPublicationMapGatewayInterface::class),
                    \Yii::createObject(\app\shared\Publications\Contract\PublicationForumLinkStoreInterface::class),
                    \Yii::createObject(\app\shared\Forum\Contract\ForumHttpClientInterface::class),
                    \Yii::createObject(\app\shared\Settings\Service\PublicationSettingsService::class),
                ),
            \app\shared\Forum\Contract\ForumPublicationMapGatewayInterface::class => static fn (): \app\shared\Forum\Infrastructure\ForumRepository =>
                new \app\shared\Forum\Infrastructure\ForumRepository(\Yii::$app->getDb()),
            \app\shared\Publications\Infrastructure\TempImageCleanup::class => static fn (): \app\shared\Publications\Infrastructure\TempImageCleanup =>
                new \app\shared\Publications\Infrastructure\TempImageCleanup(
                    logger: new \app\shared\Forum\Infrastructure\YiiPsrLoggerAdapter(\Yii::$app->getLog()->getLogger()),
                ),
            \app\shared\Publications\Contract\PublicationForumLinkStoreInterface::class => static fn (): \app\shared\Publications\Infrastructure\CachePublicationForumLinkStore =>
                new \app\shared\Publications\Infrastructure\CachePublicationForumLinkStore(\Yii::$app->getCache()),
            \app\shared\Forum\Contract\ForumHttpClientInterface::class => static function () use ($parserTunables): \app\shared\Forum\Infrastructure\ForumHttpClient {
                $tunables = $parserTunables();
                return new \app\shared\Forum\Infrastructure\ForumHttpClient(
                    (int)$tunables['http_timeout'],
                    (int)$tunables['http_retries'],
                    (int)$tunables['http_delay_microseconds'],
                    (string)$tunables['login_url'],
                    (string)(getenv('FORUM_LOGIN_USERNAME') ?: ''),
                    (string)(getenv('FORUM_LOGIN_PASSWORD') ?: ''),
                    (int)$tunables['http_max_redirects'],
                    \Yii::createObject(\app\shared\Forum\Service\ParserSettingsService::class)->bannedStatuses(),
                );
            },
            \app\shared\Forum\Service\ForumScanService::class => static function () use ($parserTunables): \app\shared\Forum\Service\ForumScanService {
                return new \app\shared\Forum\Service\ForumScanService(
                    new \app\shared\Forum\Infrastructure\ForumRepository(\Yii::$app->getDb()),
                    \Yii::createObject(\app\shared\Forum\Contract\ForumHttpClientInterface::class),
                    new \app\shared\Forum\Service\ForumHtmlParser((string)$parserTunables()['source_timezone']),
                    new \app\shared\Forum\Infrastructure\YiiPsrLoggerAdapter(\Yii::$app->getLog()->getLogger()),
                );
            },
            \app\shared\Forum\Service\ForumPostScanService::class => static function () use ($parserTunables): \app\shared\Forum\Service\ForumPostScanService {
                $tunables = $parserTunables();
                return new \app\shared\Forum\Service\ForumPostScanService(
                    new \app\shared\Forum\Infrastructure\ForumRepository(\Yii::$app->getDb()),
                    \Yii::createObject(\app\shared\Forum\Contract\ForumHttpClientInterface::class),
                    new \app\shared\Forum\Service\ForumPostPageParser((string)$tunables['source_timezone']),
                    new \app\shared\Forum\Infrastructure\YiiPsrLoggerAdapter(\Yii::$app->getLog()->getLogger()),
                    maxPages: (int)$tunables['max_pages'],
                );
            },
            \app\shared\Gallery\Service\GalleryScanService::class => static function () use ($parserTunables): \app\shared\Gallery\Service\GalleryScanService {
                $tunables = $parserTunables();
                return new \app\shared\Gallery\Service\GalleryScanService(
                    new \app\shared\Gallery\Infrastructure\GalleryRepository(\Yii::$app->getDb()),
                    \Yii::createObject(\app\shared\Forum\Contract\ForumHttpClientInterface::class),
                    new \app\shared\Gallery\Service\GalleryAlbumPageParser(),
                    new \app\shared\Forum\Infrastructure\YiiPsrLoggerAdapter(\Yii::$app->getLog()->getLogger()),
                    maxPages: (int)$tunables['max_pages'],
                );
            },
            \app\shared\Forum\Service\MemberScanService::class => static function () use ($parserTunables): \app\shared\Forum\Service\MemberScanService {
                return new \app\shared\Forum\Service\MemberScanService(
                    new \app\shared\Forum\Infrastructure\ForumRepository(\Yii::$app->getDb()),
                    \Yii::createObject(\app\shared\Forum\Contract\ForumHttpClientInterface::class),
                    new \app\shared\Forum\Service\MemberProfilePageParser((string)$parserTunables()['source_timezone']),
                    new \app\shared\Forum\Infrastructure\YiiPsrLoggerAdapter(\Yii::$app->getLog()->getLogger()),
                );
            },
        ],
    ],
    'params' => $params,
    /*
    'controllerMap' => [
        'fixture' => [ // Fixture generation command line.
            'class' => 'yii\faker\FixtureController',
        ],
    ],
    */
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => \yii\gii\Module::class,
    ];
    // The debug module is intentionally NOT enabled for the console app:
    // long-running parser commands produce huge debug logs and exhaust memory.
}

return $config;
