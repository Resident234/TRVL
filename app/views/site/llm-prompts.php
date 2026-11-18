<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<int, array{code: string, label: string, hint: string, text: string}> $settingsPrompts */
/** @var array<string, string> $templates */

use yii\helpers\Html;

$this->title = 'Промты LLM';

$settingsCount = count($settingsPrompts);
$templateCount = count($templates);
?>
<!-- Row start -->
<div class="row gx-4">
    <div class="col-12">
        <div class="card">
            <!-- The header strip of the Tasks card in the UI kit -->
            <div class="card-header d-flex justify-content-between align-items-center mt-2">
                <h5 class="card-title">Промты LLM</h5>
                <div class="d-flex gap-2">
                    <?= Html::a('<i class="bi bi-gear me-1"></i>Настройки промтов', ['site/settings'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
                    <?= Html::a('<i class="bi bi-chat-dots me-1"></i>Диалог с ИИ', ['site/publications'], ['class' => 'btn btn-primary btn-sm']) ?>
                </div>
            </div>
            <div class="card-body">
                <div class="text-muted small mb-3">
                    Промты, которыми пользуется портал, и шаблоны, которые сохранил пользователь в диалоге с ИИ,
                    лежат в разных местах и показаны здесь рядом.
                </div>

                <!-- Row start -->
                <div class="row gx-4 kanban-board">
                    <div class="col-xxl-6 col-sm-6 col-12">
                        <div class="card h-100 border-warning">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="icon-box sm bg-warning-subtle border border-warning rounded-circle">
                                        <i class="bi bi-sliders text-warning"></i>
                                    </span>
                                    <h5 class="text-warning fw-semibold m-0">Промты из настроек</h5>
                                </div>
                                <span class="badge rounded-pill bg-warning-subtle text-warning px-3 py-2">
                                    <?= str_pad((string)$settingsCount, 2, '0', STR_PAD_LEFT) ?>
                                </span>
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

                    <div class="col-xxl-6 col-sm-6 col-12">
                        <div class="card h-100 border-success">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="icon-box sm bg-success-subtle border border-success rounded-circle">
                                        <i class="bi bi-bookmarks text-success"></i>
                                    </span>
                                    <h5 class="text-success fw-semibold m-0">Шаблоны пользователя</h5>
                                </div>
                                <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2">
                                    <?= str_pad((string)$templateCount, 2, '0', STR_PAD_LEFT) ?>
                                </span>
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
                <!-- Row end -->
            </div>
        </div>
    </div>
</div>
<!-- Row end -->
