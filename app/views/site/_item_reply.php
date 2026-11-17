<?php

/** @var yii\web\View $this */
/** @var \app\shared\Forum\Dto\PostData $record */

use app\widgets\PublicationsUi;
use yii\helpers\Html;

?>
<div class="reply d-flex gap-3 mb-3">
    <?php if ($record->author?->avatarUrl !== null): ?>
        <img src="<?= Html::encode($record->author->avatarUrl) ?>" class="rounded-circle img-2x flex-shrink-0"
             alt="<?= Html::encode($record->author->name) ?>">
    <?php else: ?>
        <span class="rounded-circle img-2x flex-shrink-0 bg-secondary-subtle d-flex align-items-center justify-content-center">
            <i class="bi bi-person-fill text-secondary"></i>
        </span>
    <?php endif ?>
    <div>
        <?php if ($record->title !== ''): ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <h6 class="fw-bold mb-0"><?= Html::encode($record->title) ?></h6>
                <span class="d-flex align-items-center gap-1">
                    <?php if ($record->publicationStatus !== null): ?>
                        <span class="badge <?= $record->publicationStatus === 'published' ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                            <?= $record->publicationStatus === 'published' ? 'Опубликовано' : 'Просмотрено' ?>
                        </span>
                    <?php endif ?>
                    <?php if ($record->publicationTelegramId !== null): ?>
                        <span class="badge rounded-pill bg-info text-dark" title="telegram_id">TG: <?= Html::encode($record->publicationTelegramId) ?></span>
                    <?php endif ?>
                </span>
            </div>
        <?php elseif ($record->publicationStatus !== null || $record->publicationTelegramId !== null): ?>
            <span class="d-inline-block mb-1">
                <?php if ($record->publicationStatus !== null): ?>
                    <span class="badge <?= $record->publicationStatus === 'published' ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                        <?= $record->publicationStatus === 'published' ? 'Опубликовано' : 'Просмотрено' ?>
                    </span>
                <?php endif ?>
                <?php if ($record->publicationTelegramId !== null): ?>
                    <span class="badge rounded-pill bg-info text-dark" title="telegram_id">TG: <?= Html::encode($record->publicationTelegramId) ?></span>
                <?php endif ?>
            </span>
        <?php endif ?>
        <p class="mb-1" style="white-space: pre-line; word-break: break-word;"><?= Html::encode($record->contentText) ?></p>
        <?php if ($record->contentHtml !== ''): ?>
            <details class="mb-1">
                <summary class="text-muted small">
                    <i class="bi bi-code-slash me-1"></i>HTML-исходник
                </summary>
                <pre class="small text-muted mb-0"
                     style="white-space: pre-wrap; word-break: break-word;"><?= Html::encode($record->contentHtml) ?></pre>
            </details>
        <?php endif ?>
        <small class="text-muted d-block">
            <?= Html::encode($record->author?->name ?? 'Неизвестный автор') ?>
            <?php if ($record->number !== null): ?>
                • пост #<?= $record->number ?>
            <?php endif ?>
            <?php if ($record->postedAt !== null): ?>
                • <?= Html::encode($this->context->formatForumDate($record->postedAt)) ?>
            <?php endif ?>
        </small>
        <small class="text-muted d-block mt-1">
            <a href="<?= Html::encode($record->sourceUrl) ?>" target="_blank" rel="noopener"
               class="text-muted"><?= Html::encode($record->sourceUrl) ?></a>
        </small>
        <div class="d-flex align-items-center gap-2 mt-2 mb-1 flex-wrap">
            <?= PublicationsUi::viewedForm($record->id, 'post') ?>
            <?= PublicationsUi::publishButton($record->contentText, 'post', $record->id, $record->imageUrls, $record->title) ?>
        </div>
        <?php if ($record->imageUrls !== []): ?>
            <div class="d-flex mt-2 flex-wrap align-items-start">
                <?php foreach ($record->imageUrls as $imageUrl): ?>
                    <a href="<?= Html::encode($imageUrl) ?>" target="_blank" rel="noopener" class="d-inline-block mb-3">
                        <img src="<?= Html::encode($imageUrl) ?>" class="img-3x rounded-2 me-3"
                             style="object-fit: cover;"
                             alt="Изображение поста">
                    </a>
                <?php endforeach ?>
            </div>
            <details class="mt-1">
                <summary class="text-muted small">
                    <i class="bi bi-link-45deg me-1"></i>Ссылки на изображения (<?= count($record->imageUrls) ?>)
                </summary>
                <?php foreach ($record->imageUrls as $imageUrl): ?>
                    <div class="text-muted small">
                        <a href="<?= Html::encode($imageUrl) ?>" target="_blank" rel="noopener"
                           class="text-muted" style="word-break: break-all;"><?= Html::encode($imageUrl) ?></a>
                    </div>
                <?php endforeach ?>
            </details>
        <?php endif ?>
    </div>
</div>
