<?php

/** @var yii\web\View $this */
/** @var \app\shared\Publications\Dto\PublicationData $record */

use app\widgets\PublicationsUi;
use yii\helpers\Html;

[$heading, $body] = $record->headingAndBody();

?>
<div class="activity-log" data-text="<?= Html::encode($record->text) ?>"
     data-formatting="<?= Html::encode($record->formatting->toJson()) ?>"
     data-button="<?= Html::encode($record->buttons->toJson()) ?>"
     data-title="<?= Html::encode($record->title) ?>"
     data-source-type="draft" data-source-id="<?= $record->id ?>"
     data-image-urls="<?= Html::encode(implode("\n", $record->imageUrls)) ?>">
    <div class="d-flex align-items-center gap-2 mb-1">
        <a href="#" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="Редактировать">
            <i class="bi bi-pencil-square"></i>
        </a>
        <?= PublicationsUi::deleteForm($record->id, 'draft') ?>
        <?= PublicationsUi::publishForm($record->id, 'draft') ?>
        <?= PublicationsUi::scheduleButton($record->id, 'draft') ?>
    </div>
    <?php if ($heading !== ''): ?>
        <p class="mb-1 fw-bold"><?= Html::encode($heading) ?></p>
    <?php endif ?>
    <p class="mb-1" style="white-space: pre-line; word-break: break-word;"><?= Html::encode($body) ?></p>
    <?= PublicationsUi::stackedImages($record->imageUrls) ?>
    <?= PublicationsUi::dateMeta([
        'Создано' => $record->createdAt,
        'Обновлено' => $record->updatedAt,
    ]) ?>
    <span class="badge bg-secondary mt-2">Черновик</span>
    <span class="badge bg-warning text-dark mt-2 d-none editing-badge">Редактируется</span>
</div>
