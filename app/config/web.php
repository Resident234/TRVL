<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

// The HTTP client of the forum behaves as parser_config says, exactly like in
// the console app: the settings service is a singleton, so a request reads the
// table once and every factory asks it for the numbers it needs.
$parserTunables = static fn (): array =>
    \Yii::createObject(\app\shared\Forum\Service\ParserSettingsService::class)->tunables();

$config = [
    'id' => 'basic',
    'name' => 'TRVL',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'container' => [
        'singletons' => [
            \yii\mail\MailerInterface::class => [
                'class' => \yii\symfonymailer\Mailer::class,
                // send all mails to a file by default.
                'useFileTransport' => true,
                'viewPath' => '@app/mail',
            ],
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
                    null,
                    \Yii::createObject(\app\shared\Telegram\Service\ChannelService::class),
                    \Yii::createObject(\app\shared\Forum\Contract\ForumPublicationMapGatewayInterface::class),
                    \Yii::createObject(\app\shared\Publications\Contract\PublicationForumLinkStoreInterface::class),
                    \Yii::createObject(\app\shared\Forum\Contract\ForumHttpClientInterface::class),
                    \Yii::createObject(\app\shared\Settings\Service\PublicationSettingsService::class),
                ),
            \app\shared\Settings\Service\PublicationSettingsService::class => static fn (): \app\shared\Settings\Service\PublicationSettingsService =>
                new \app\shared\Settings\Service\PublicationSettingsService(
                    \Yii::createObject(\app\shared\Settings\Contract\PublicationSettingsRepositoryInterface::class),
                    (string)(getenv('TELEGRAM_PUBLISH_CRON_SCHEDULE') ?: ''),
                ),
            \app\shared\Forum\Service\ParserSettingsService::class => static fn (): \app\shared\Forum\Service\ParserSettingsService =>
                new \app\shared\Forum\Service\ParserSettingsService(
                    \Yii::createObject(\app\shared\Forum\Contract\ForumRepositoryInterface::class),
                ),
            \app\shared\Settings\Contract\PublicationSettingsRepositoryInterface::class => static fn (): \app\shared\Settings\Infrastructure\PublicationSettingsRepository =>
                new \app\shared\Settings\Infrastructure\PublicationSettingsRepository(\Yii::$app->getDb()),
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
            \app\shared\Forum\Contract\ForumRepositoryInterface::class => static fn (): \app\shared\Forum\Infrastructure\ForumRepository =>
                new \app\shared\Forum\Infrastructure\ForumRepository(\Yii::$app->getDb()),
            \app\shared\Forum\Contract\ForumPublicationMapGatewayInterface::class => static fn (): \app\shared\Forum\Infrastructure\ForumRepository =>
                new \app\shared\Forum\Infrastructure\ForumRepository(\Yii::$app->getDb()),
            \app\shared\Publications\Contract\PublicationForumLinkStoreInterface::class => static fn (): \app\shared\Publications\Infrastructure\CachePublicationForumLinkStore =>
                new \app\shared\Publications\Infrastructure\CachePublicationForumLinkStore(\Yii::$app->getCache()),
        ],
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        // One jQuery per page, and it is the copy the UI-kit ships. Any bundle
        // that asks for jQuery — every `yii\bootstrap5\Widget` does — used to
        // print Yii's own copy after the UI-kit one, which replaced
        // `window.jQuery` and left the plugins registered on the discarded
        // copy: the date picker and the scrollbars were gone by the time the
        // page script ran. Publishing the UI-kit file through `JqueryAsset`
        // keeps a single copy and lets the dependency order put it first.
        'assetManager' => [
            'bundles' => [
                \yii\web\JqueryAsset::class => [
                    'sourcePath' => '@webroot/ui-kit/assets/js',
                    'js' => ['jquery.min.js'],
                    'publishOptions' => [
                        'only' => ['jquery.min.js'],
                    ],
                ],
            ],
        ],
        'request' => [
            // The secret lives in the environment (`COOKIE_VALIDATION_KEY` in
            // `.env`), never in the repository. Without it Yii cannot sign the
            // session and CSRF cookies, so the portal does not accept any
            // request that carries one.
            'cookieValidationKey' => (string)(getenv('COOKIE_VALIDATION_KEY') ?: ''),
        ],
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
        'user' => [
            'identityClass' => \app\models\User::class,
            'enableAutoLogin' => true,
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'mailer' => \yii\mail\MailerInterface::class,
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                    // Same rule as in console.php: without it every export ends
                    // with a dump of the superglobals, which for a request means
                    // the login form password and the session cookies.
                    'logVars' => [],
                ],
            ],
        ],
        'db' => $db,
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            // Every route a link is built for gets its own flat address; the
            // rules are read in order, so the homepage alias has to come
            // first. Routes left out here keep working through the default
            // <controller>/<action> form, which is how the dev modules
            // (/gii, /debug) and site/error stay reachable.
            'rules' => [
                '' => 'site/index',
                'publications' => 'site/publications',
                'settings' => 'site/settings',
                'settings-save' => 'site/settings-save',
                'parser-settings' => 'site/parser-settings',
                'parser-settings-save' => 'site/parser-settings-save',
                'channel-description' => 'site/channel-description',
                'publication-create' => 'site/publication-create',
                'publication-publish' => 'site/publication-publish',
                'publication-to-draft' => 'site/publication-to-draft',
                'publication-schedule' => 'site/publication-schedule',
                'publication-delete' => 'site/publication-delete',
                'publication-page' => 'site/publication-page',
                'publication-sort' => 'site/publication-sort',
                'title-from-first-line-save' => 'site/title-from-first-line-save',
                'forum-text-order-save' => 'site/forum-text-order-save',
                'forum-viewed' => 'site/forum-viewed',
                'forum-filter-save' => 'site/forum-filter-save',
                'forum-filter-clear' => 'site/forum-filter-clear',
                'forum-post-page' => 'site/forum-post-page',
                'forum-thread' => 'site/forum-thread',
                'login' => 'site/login',
                'logout' => 'site/logout',
                'contact' => 'site/contact',
                'about' => 'site/about',
                'captcha' => 'site/captcha',
            ],
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => \yii\debug\Module::class,
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];

    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => \yii\gii\Module::class,
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];
}

return $config;
