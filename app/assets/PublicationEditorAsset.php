<?php

declare(strict_types=1);

namespace app\assets;

use yii\web\AssetBundle;
use yii\web\View;

/**
 * Quill editor for the publication text parts. Registered only by the
 * publications view: the rest of the portal has no rich-text input.
 */
class PublicationEditorAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = [
        'ui-kit/assets/vendor/quill/quill.snow.css',
        'ui-kit/assets/css/publication-editor.css',
    ];
    public $js = [
        'ui-kit/assets/vendor/quill/quill.js',
    ];
    public $jsOptions = [
        'position' => View::POS_END,
    ];
    public $depends = [
        DashboardAsset::class,
    ];
}
