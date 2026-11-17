<?php

/** @var yii\web\View $this */
/** @var array{topic: \app\shared\Forum\Dto\TopicData, posts: \app\shared\Forum\Dto\PostData[], postsTotal: int} $record */

use app\widgets\PublicationsUi;
use yii\helpers\Html;

$topic = $record['topic'];
$posts = $record['posts'];
?>
<div class="thread mb-4 pb-3 border-bottom">
    <div class="d-flex align-items-start gap-3 mb-3">
        <?php if ($topic->author?->avatarUrl !== null): ?>
            <img src="<?= Html::encode($topic->author->avatarUrl) ?>"
                 class="rounded-circle img-3x flex-shrink-0"
                 alt="<?= Html::encode($topic->author->name) ?>">
        <?php else: ?>
            <span class="rounded-circle img-3x flex-shrink-0 bg-primary-subtle d-flex align-items-center justify-content-center">
                <i class="bi bi-person-fill text-primary"></i>
            </span>
        <?php endif ?>
        <div class="flex-grow-1">
            <div class="thread-header d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0"><?= Html::encode($topic->title) ?></h6>
                <span class="d-flex align-items-center gap-1">
                    <?php if ($topic->publicationStatus !== null): ?>
                        <span class="badge <?= $topic->publicationStatus === 'published' ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                            <?= $topic->publicationStatus === 'published' ? 'Опубликовано' : 'Просмотрено' ?>
                        </span>
                    <?php endif ?>
                    <?php if ($topic->publicationTelegramId !== null): ?>
                        <span class="badge rounded-pill bg-dark" title="telegram_id">TG: <?= Html::encode($topic->publicationTelegramId) ?></span>
                    <?php endif ?>
                    <span class="badge bg-primary">#<?= $topic->id ?></span>
                </span>
            </div>
            <p class="mb-2" style="white-space: pre-line; word-break: break-word;"><?= Html::encode($topic->contentText) ?></p>
            <?php if ($topic->contentHtml !== ''): ?>
                <details class="mb-2">
                    <summary class="text-muted small">
                        <i class="bi bi-code-slash me-1"></i>HTML-исходник
                    </summary>
                    <pre class="small text-muted mb-0"
                         style="white-space: pre-wrap; word-break: break-word;"><?= Html::encode($topic->contentHtml) ?></pre>
                </details>
            <?php endif ?>
            <div class="thread-meta d-flex align-items-center text-muted small flex-wrap">
                <span class="me-3"><i class="bi bi-person"></i>
                    <?= Html::encode($topic->author?->name ?? 'Неизвестный автор') ?>
                </span>
                <span class="me-3"><i class="bi bi-clock"></i>
                    <?= Html::encode($this->context->formatForumDate($topic->publishedAt)) ?>
                </span>
                <span class="me-3"><i class="bi bi-chat-dots"></i> <?= $record['postsTotal'] ?></span>
            </div>
            <div class="thread-meta text-muted small mt-1">
                <i class="bi bi-link-45deg"></i>
                <a href="<?= Html::encode($topic->sourceUrl) ?>" target="_blank" rel="noopener"
                   class="text-muted"><?= Html::encode($topic->sourceUrl) ?></a>
            </div>
            <div class="d-flex align-items-center gap-2 mt-2 mb-1 flex-wrap">
                <?= PublicationsUi::viewedForm($topic->id, 'topic') ?>
                <?= PublicationsUi::publishButton($topic->contentText, 'topic', $topic->id, $topic->imageUrls, $topic->title) ?>
                <?php /* A thread with no posts behind it is the topic alone, which
                        the button above already loads. */ ?>
                <?php if ($record['postsTotal'] > 0): ?>
                    <?= PublicationsUi::threadButtons($topic->id) ?>
                <?php endif ?>
            </div>
            <?php if ($topic->imageUrls !== []): ?>
                <div class="d-flex mt-2 flex-wrap align-items-start">
                    <?php foreach ($topic->imageUrls as $imageUrl): ?>
                        <a href="<?= Html::encode($imageUrl) ?>" target="_blank" rel="noopener" class="d-inline-block mb-3">
                            <img src="<?= Html::encode($imageUrl) ?>" class="img-3x rounded-2 me-3"
                                 style="object-fit: cover;"
                                 alt="Изображение темы">
                        </a>
                    <?php endforeach ?>
                </div>
                <details class="mt-1">
                    <summary class="text-muted small">
                        <i class="bi bi-link-45deg me-1"></i>Ссылки на изображения (<?= count($topic->imageUrls) ?>)
                    </summary>
                    <?php foreach ($topic->imageUrls as $imageUrl): ?>
                        <div class="text-muted small">
                            <a href="<?= Html::encode($imageUrl) ?>" target="_blank" rel="noopener"
                               class="text-muted" style="word-break: break-all;"><?= Html::encode($imageUrl) ?></a>
                        </div>
                    <?php endforeach ?>
                </details>
            <?php endif ?>
        </div>
    </div>
    <?php if ($posts !== []): ?>
        <?php /* Its own scroller, so the scroll of the topic list and the scroll of
                one discussion never ask the server for the wrong page. */ ?>
        <div class="thread-replies ms-5"
             data-topic="<?= $topic->id ?>"
             data-offset="<?= count($posts) ?>"
             data-total="<?= $record['postsTotal'] ?>">
            <?php foreach ($posts as $post): ?>
                <?= $this->render('_item_reply', ['record' => $post]) ?>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>
