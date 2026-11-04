<?php

declare(strict_types=1);

namespace app\assets;

use yii\web\AssetBundle;
use yii\web\View;

/**
 * The searchable selector behind «Активный стиль текста». Registered only by the
 * settings view: no other page of the portal offers a choice read off the disk, and
 * the widget replaces the `<select>` it is mounted on, so a page that does not ask
 * for it must not carry it.
 */
class TextStyleSelectAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = [
        'ui-kit/assets/vendor/select2/select2.min.css',
    ];
    public $js = [
        'ui-kit/assets/vendor/select2/select2.min.js',
        'js/text-style-select.js',
    ];
    public $jsOptions = [
        'position' => View::POS_END,
    ];
    // The jQuery the widget registers onto arrives with the dashboard bundle.
    public $depends = [
        DashboardAsset::class,
    ];
}
