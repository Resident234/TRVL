<?php

declare(strict_types=1);

namespace app\assets;

use yii\web\AssetBundle;
use yii\web\JqueryAsset;

/**
 * UI-kit based asset bundle for the TRVL dashboard.
 */
class DashboardAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = [
        'ui-kit/assets/fonts/bootstrap/bootstrap-icons.css',
        'ui-kit/assets/css/main.min.css',
        'ui-kit/assets/vendor/overlay-scroll/OverlayScrollbars.min.css',
        'ui-kit/assets/vendor/daterange/daterange.css',
    ];
    public $js = [
        'ui-kit/assets/js/bootstrap.bundle.min.js',
        'ui-kit/assets/js/moment.min.js',
        'ui-kit/assets/js/moment-timezone-with-data.min.js',
        'ui-kit/assets/vendor/overlay-scroll/jquery.overlayScrollbars.min.js',
        'ui-kit/assets/vendor/overlay-scroll/custom-scrollbar.js',
        'ui-kit/assets/vendor/daterange/daterange.js',
        'ui-kit/assets/vendor/daterange/custom-daterange.js',
        'ui-kit/assets/js/custom.js',
    ];
    // The jQuery the plugin files above register onto. It is the UI-kit's own
    // copy, because `assetManager` in `config/web.php` points `JqueryAsset` at
    // that file — naming the bundle here only puts the copy before them.
    public $depends = [
        JqueryAsset::class,
    ];
}
