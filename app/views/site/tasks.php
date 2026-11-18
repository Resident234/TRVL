<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<int, array{code: string, label: string, hint: string, text: string}> $settingsPrompts */
/** @var array<string, string> $templates */

use yii\helpers\Html;

$this->title = 'Шаблоны промтов';

$settingsCount = count($settingsPrompts);
$templateCount = count($templates);
?>
<div class="row g-3">
    <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h4 class="mb-1">Шаблоны промтов</h4>
            <div class="text-muted small">
                Промты, которыми пользуется портал, и шаблоны, которые сохранил пользователь в диалоге с ИИ,
                лежат в разных местах и показаны здесь рядом.
            </div>
        </div>
        <div class="d-flex gap-2">
            <?= Html::a('<i class="bi bi-gear me-1"></i>Настройки промтов', ['site/settings'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
            <?= Html::a('<i class="bi bi-chat-dots me-1"></i>Диалог с ИИ', ['site/publications'], ['class' => 'btn btn-outline-info btn-sm']) ?>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100 border-warning">
            <div class="card-header d-flex align-items-center justify-content-between bg-warning-subtle border-warning">
                <span class="fw-semibold text-warning-emphasis">
                    <i class="bi bi-sliders me-1"></i>Промты из настроек
                </span>
                <span class="badge rounded-pill bg-warning text-dark"><?= str_pad((string)$settingsCount, 2, '0', STR_PAD_LEFT) ?></span>
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <?php foreach ($settingsPrompts as $prompt): ?>
                    <div class="border rounded-3 p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <h6 class="fw-bold mb-0"><?= Html::encode($prompt['label']) ?></h6>
                            <code class="small text-muted"><?= Html::encode($prompt['code']) ?></code>
                        </div>
                        <?php if ($prompt['hint'] !== ''): ?>
                            <div class="text-muted small mb-2"><?= Html::encode($prompt['hint']) ?></div>
                        <?php endif ?>
                        <pre class="mb-0 small bg-light border rounded p-2" style="white-space: pre-wrap; word-break: break-word;"><?= Html::encode($prompt['text']) ?></pre>
                    </div>
                <?php endforeach ?>
                <?php if ($settingsPrompts === []): ?>
                    <div class="text-muted small">В настройках нет ни одного промта.</div>
                <?php endif ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100 border-success">
            <div class="card-header d-flex align-items-center justify-content-between bg-success-subtle border-success">
                <span class="fw-semibold text-success-emphasis">
                    <i class="bi bi-bookmarks me-1"></i>Шаблоны пользователя
                </span>
                <span class="badge rounded-pill bg-success"><?= str_pad((string)$templateCount, 2, '0', STR_PAD_LEFT) ?></span>
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <?php foreach ($templates as $code => $text): ?>
                    <div class="border rounded-3 p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="fw-semibold small">Шаблон промта</span>
                            <code class="small text-muted" title="<?= Html::encode((string)$code) ?>">
                                <?= Html::encode(substr((string)$code, -12)) ?>
                            </code>
                        </div>
                        <pre class="mb-0 small bg-light border rounded p-2" style="white-space: pre-wrap; word-break: break-word;"><?= Html::encode($text) ?></pre>
                    </div>
                <?php endforeach ?>
                <?php if ($templates === []): ?>
                    <div class="text-muted small">
                        Шаблонов пока нет. Нажмите «Сохранить как шаблон» под репликой пользователя в диалоге с ИИ.
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>
