<?php

/** @var yii\web\View $this */
/** @var \app\shared\Publications\Dto\PublicationData $record */
/** @var string $now */

use app\widgets\PublicationsUi;
use yii\helpers\Html;

$isSent = $record->telegramId !== null;

[$heading, $body] = $record->headingAndBody();

?>
<div class="activity-log" data-text="<?= Html::encode($record->text) ?>"
     data-formatting="<?= Html::encode($record->formatting->toJson()) ?>"
     data-button="<?= Html::encode($record->buttons->toJson()) ?>"
     data-title="<?= Html::encode($record->title) ?>"
     data-source-type="post" data-source-id="<?= $record->id ?>"
     data-published-at-utc="<?= Html::encode($record->publishedAt ?? '') ?>"
     data-image-urls="<?= Html::encode(implode("\n", $record->imageUrls)) ?>">
    <div class="d-flex align-items-center gap-2 mb-1">
        <?php if ($record->telegramId !== null): ?>
            <p class="mb-0">
                <span class="text-primary">#<?= $record->telegramId ?></span>
            </p>
        <?php endif ?>
        <a href="#" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="Редактировать">
            <i class="bi bi-pencil-square"></i>
        </a>
        <?= PublicationsUi::deleteForm($record->id, 'post') ?>
        <?php if (!$isSent): ?>
            <?= PublicationsUi::publishForm($record->id, 'post') ?>
        <?php endif ?>
        <?= PublicationsUi::toDraftForm($record->id) ?>
    </div>
    <?php if ($heading !== ''): ?>
        <p class="mb-1 fw-bold"><?= Html::encode($heading) ?></p>
    <?php endif ?>
    <p class="mb-1" style="white-space: pre-line; word-break: break-word;"><?= Html::encode($body) ?></p>
    <?= PublicationsUi::stackedImages($record->imageUrls) ?>
    <?= PublicationsUi::dateMeta([
        $isSent ? 'Опубликовано' : 'Запланировано' => $record->publishedAt,
        'Создано' => $record->createdAt,
        'Обновлено' => $record->updatedAt,
    ]) ?>
    <?= PublicationsUi::statusBadge($record, $now) ?>
    <span class="badge bg-warning text-dark mt-2 d-none editing-badge">Редактируется</span>
</div>
