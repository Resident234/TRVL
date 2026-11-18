<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $content */

use app\assets\DashboardAsset;
use app\widgets\Alert;
use yii\helpers\Html;
use yii\helpers\Url;

DashboardAsset::register($this);

$brandUrl = Yii::getAlias('@web/images');

$this->render('_favicon');
$this->registerCsrfMetaTags();
$this->registerMetaTag(
    ['charset' => Yii::$app->charset],
    'charset',
);
$this->registerMetaTag(
    [
        'name' => 'viewport',
        'content' => 'width=device-width, initial-scale=1',
    ],
);

// The ui-kit stylesheet keeps the sidebar toggle button mobile-only
// (display:none on desktop) and has no desktop collapsed state. Show
// the button on desktop and collapse the sidebar via the toggled class.
$this->registerCss(
    <<<CSS
.app-header #toggle-sidebar {
    display: flex;
    width: 40px;
    height: 30px;
    align-items: center;
    justify-content: center;
    border-radius: 50px;
}
.app-brand .logo-mini {
    display: none;
}
@media (min-width: 992px) {
    .page-wrapper.toggled .sidebar-wrapper {
        left: -255px;
    }
    .page-wrapper.toggled .main-container {
        padding-left: 0;
    }
    /* Pinned, the sidebar is 70px wide and .app-brand only clips what does not
       fit: the wordmark would show as a ring plus a sliver of the first letter,
       so the square mark takes its place. */
    .page-wrapper.pinned:not(.sidebar-hovered) .app-brand .logo-full {
        display: none;
    }
    .page-wrapper.pinned:not(.sidebar-hovered) .app-brand .logo-mini {
        display: block;
        width: 46px;
        height: 46px;
    }
}
CSS
);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <?php $this->head() ?>
    <title><?= Html::encode($this->title) ?></title>
</head>
<body>
<?php $this->beginBody() ?>

<script>
(function () {
    'use strict';

    var COOKIE_NAME = 'portal_tz';

    function getBrowserTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch (e) {
            return null;
        }
    }

    function readCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function writeCookie(name, value, days) {
        var expires = '';
        if (days) {
            var d = new Date();
            d.setTime(d.getTime() + days * 86400000);
            expires = '; expires=' + d.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    var storedTz = readCookie(COOKIE_NAME);
    if (storedTz) {
        try { new Date().toLocaleString('en', { timeZone: storedTz }); } catch (e) { storedTz = null; }
    }
    if (!storedTz) {
        storedTz = getBrowserTimezone();
        if (storedTz) {
            writeCookie(COOKIE_NAME, storedTz, 365);
        }
    }
    window.portalUserTimezone = storedTz || 'UTC';
})();

function getPortalTimezone() {
    var tz = window.portalUserTimezone;
    if (!tz) {
        try {
            tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch (e) {
            tz = 'UTC';
        }
        window.portalUserTimezone = tz;
    }
    return tz;
}
</script>

<div class="page-wrapper">

    <div class="main-container">

        <nav id="sidebar" class="sidebar-wrapper">

            <div class="app-brand">
                <a href="<?= Yii::$app->homeUrl ?>">
                    <img src="<?= $brandUrl ?>/trvl-logo.svg" class="logo logo-full" alt="TRVL">
                    <img src="<?= $brandUrl ?>/trvl-mark.svg" class="logo logo-mini" alt="TRVL">
                </a>
            </div>

            <div class="sidebarMenuScroll">
                <ul class="sidebar-menu">
                    <?php $currentRoute = Yii::$app->controller->id . '/' . Yii::$app->controller->action->id; ?>
                    <li class="<?= $currentRoute === 'site/index' ? 'active current-page' : '' ?>">
                        <a href="<?= Yii::$app->homeUrl ?>">
                            <i class="bi bi-speedometer2"></i>
                            <span class="menu-text">Дашборд</span>
                        </a>
                    </li>
                    <li class="<?= $currentRoute === 'site/publications' ? 'active current-page' : '' ?>">
                        <a href="<?= Url::to(['site/publications']) ?>">
                            <i class="bi bi-collection"></i>
                            <span class="menu-text">Публикации в канал</span>
                        </a>
                    </li>
                    <li class="<?= $currentRoute === 'site/settings' ? 'active current-page' : '' ?>">
                        <a href="<?= Url::to(['site/settings']) ?>">
                            <i class="bi bi-gear"></i>
                            <span class="menu-text">Настройки публикаций</span>
                        </a>
                    </li>
                    <li class="<?= $currentRoute === 'site/parser-settings' ? 'active current-page' : '' ?>">
                        <a href="<?= Url::to(['site/parser-settings']) ?>">
                            <i class="bi bi-cloud-download"></i>
                            <span class="menu-text">Настройки парсера</span>
                        </a>
                    </li>
                    <li class="<?= $currentRoute === 'site/llm-prompts' ? 'active current-page' : '' ?>">
                        <a href="<?= Url::to(['site/llm-prompts']) ?>">
                            <i class="bi bi-file-earmark-text"></i>
                            <span class="menu-text">Шаблоны промтов</span>
                        </a>
                    </li>
                </ul>
            </div>

        </nav>

        <div class="app-container">

            <div class="app-header d-flex align-items-center">

                <div class="d-flex">
                    <button type="button" class="btn bg-primary me-2 toggle-sidebar" id="toggle-sidebar">
                        <i class="bi bi-layout-sidebar fs-5 text-white"></i>
                    </button>
                    <button type="button" class="btn bg-primary-subtle me-2 pin-sidebar" id="pin-sidebar">
                        <i class="bi bi-layout-sidebar fs-5 text-primary"></i>
                    </button>
                </div>

                <div class="app-brand-sm d-lg-none d-md-block">
                    <a href="<?= Yii::$app->homeUrl ?>">
                        <img src="<?= $brandUrl ?>/trvl-mark.svg" class="logo" alt="TRVL">
                    </a>
                </div>

                <div class="header-actions">
                    <div class="search-container d-lg-block d-none me-3">
                        <input type="text" class="form-control" id="searchAny" placeholder="Поиск">
                        <i class="bi bi-search"></i>
                    </div>

                    <div class="dropdown ms-3">
                        <a id="userSettings" class="dropdown-toggle d-flex align-items-center py-1 avatar-box" href="#!"
                           role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle fs-3 lh-1 text-secondary"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end shadow-lg p-3">
                            <div class="user-header d-flex align-items-center mb-3">
                                <i class="bi bi-person-circle fs-2 me-2 text-primary"></i>
                                <div>
                                    <h6 class="mb-0">Администратор</h6>
                                    <small class="text-muted">TRVL</small>
                                </div>
                            </div>
                            <?php if (Yii::$app->user->isGuest): ?>
                                <a class="dropdown-item d-flex align-items-center py-2 border mb-1" href="<?= Url::to(['/site/login']) ?>">
                                    <i class="bi bi-box-arrow-in-right me-2 text-primary"></i>
                                    <span>Войти</span>
                                </a>
                            <?php else: ?>
                                <a class="dropdown-item d-flex align-items-center py-2 border mb-1" href="#!">
                                    <i class="bi bi-person me-2 text-primary"></i>
                                    <span>Профиль</span>
                                </a>
                                <?= Html::a(
                                    '<i class="bi bi-box-arrow-right me-2 text-primary"></i>Выйти',
                                    ['/site/logout'],
                                    [
                                        'class' => 'dropdown-item d-flex align-items-center py-2 border',
                                        'data-method' => 'post',
                                    ],
                                ) ?>
                            <?php endif ?>
                        </div>
                    </div>
                </div>

            </div>

            <div class="app-hero-header d-flex align-items-center">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <i class="bi bi-house"></i>
                        <a href="<?= Yii::$app->homeUrl ?>">Home</a>
                    </li>
                    <li class="breadcrumb-item" aria-current="page"><?= Html::encode($this->title) ?></li>
                </ol>
            </div>

            <div class="app-body">
                <div id="app-flash"><?= Alert::widget() ?></div>
                <?= $content ?>
            </div>

            <div class="app-footer">
                <span>© TRVL <?= date('Y') ?></span>
            </div>

        </div>

    </div>

</div>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
