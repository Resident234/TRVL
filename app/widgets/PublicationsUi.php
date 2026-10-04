<?php

declare(strict_types=1);

namespace app\widgets;

use app\shared\Publications\Dto\PublicationData;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Builders for the publication list controls. Shared by the publications page
 * and by the block partials re-rendered over AJAX, so the two can never render
 * divergent markup for the same action.
 */
final class PublicationsUi
{
    private static function csrf(): string
    {
        return '<input type="hidden" name="' . Yii::$app->request->csrfParam
            . '" value="' . Yii::$app->request->csrfToken . '">';
    }

    public static function deleteForm(int $id, string $source): string
    {
        return '<form method="post" action="' . Url::to(['site/publication-delete'])
            . '" class="d-inline" data-ajax>' . self::csrf()
            . '<input type="hidden" name="publicationSource" value="' . $source . '">'
            . '<input type="hidden" name="publicationId" value="' . $id . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3" '
            . 'title="Удалить"><i class="bi bi-trash"></i></button></form>';
    }

    public static function viewedForm(int $id, string $type): string
    {
        return '<form method="post" action="' . Url::to(['site/forum-viewed'])
            . '" class="d-inline" data-ajax>' . self::csrf()
            . '<input type="hidden" name="forumEntityType" value="' . $type . '">'
            . '<input type="hidden" name="forumEntityId" value="' . $id . '">'
            . '<button type="submit" class="btn btn-outline-primary btn-sm">'
            . '<i class="bi bi-check2-square me-1"></i>Просмотрено</button></form>';
    }

    public static function publishForm(int $id, string $source): string
    {
        return '<form method="post" action="' . Url::to(['site/publication-publish'])
            . '" class="d-inline" data-ajax>' . self::csrf()
            . '<input type="hidden" name="publicationSource" value="' . $source . '">'
            . '<input type="hidden" name="publicationId" value="' . $id . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-3" '
            . 'title="Опубликовать"><i class="bi bi-send"></i></button></form>';
    }

    public static function toDraftForm(int $id, ?string $source = null, string $title = 'Переместить в черновик'): string
    {
        $sourceField = $source === null
            ? ''
            : '<input type="hidden" name="publicationSource" value="' . $source . '">';

        return '<form method="post" action="' . Url::to(['site/publication-to-draft'])
            . '" class="d-inline" data-no-edit="1" data-ajax>' . self::csrf()
            . $sourceField
            . '<input type="hidden" name="publicationId" value="' . $id . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-warning rounded-pill px-3" '
            . 'title="' . $title . '"><i class="bi bi-file-earmark-arrow-down"></i></button></form>';
    }

    public static function scheduleButton(int $id, string $source): string
    {
        return '<a href="#" class="btn btn-sm btn-outline-info rounded-pill px-3" title="Запланировать публикацию"'
            . ' data-bs-toggle="modal" data-bs-target="#scheduleModal"'
            . ' data-source="' . $source . '" data-draft-id="' . $id . '">'
            . '<i class="bi bi-calendar2-plus"></i></a>';
    }

    public static function publishButton(string $text, string $forumType, int $forumId, array $imageUrls = [], string $title = ''): string
    {
        $imagesAttr = $imageUrls === [] ? '' : ' data-image-urls="' . Html::encode(implode("\n", $imageUrls)) . '"';
        $titleAttr = $title !== '' ? ' data-title="' . Html::encode($title) . '"' : '';

        return '<button type="button" class="btn btn-outline-primary btn-sm forum-publish-btn" data-text="'
            . Html::encode($text) . '" data-forum-type="' . $forumType . '" data-forum-id="' . $forumId . '"'
            . $imagesAttr . $titleAttr . '>'
            . '<i class="bi bi-send me-1"></i>Опубликовать</button>';
    }

    /**
     * The two «в форму» buttons of a whole discussion: the topic with all of its
     * posts as one text, or with every post a part of its own. They carry no text
     * themselves — the posts are read from the table when a button is pressed,
     * and the topic the block already hands over on its own publish button.
     */
    public static function threadButtons(int $topicId): string
    {
        $merged = '<button type="button" class="btn btn-outline-primary btn-sm forum-thread-btn"'
            . ' data-thread="merged" data-topic="' . $topicId . '"'
            . ' title="Загрузить в форму текст топика и всех его постов одним текстом">'
            . '<i class="bi bi-file-earmark-text me-1"></i>Тред целиком</button>';
        $parts = '<button type="button" class="btn btn-outline-primary btn-sm forum-thread-btn"'
            . ' data-thread="parts" data-topic="' . $topicId . '"'
            . ' title="Загрузить топик и каждый его пост отдельной частью, в порядке блока">'
            . '<i class="bi bi-card-list me-1"></i>Тред по частям</button>';

        return $merged . $parts;
    }

    /**
     * One line per timestamp the record actually carries. Values stay in
     * UTC and are printed by the client in the viewer's timezone, so a
     * label is dropped rather than shown with an empty value.
     *
     * @param array<string, string|null> $dates label => UTC timestamp
     */
    public static function dateMeta(array $dates): string
    {
        $lines = '';
        foreach ($dates as $label => $utc) {
            if ($utc === null || $utc === '') {
                continue;
            }

            $lines .= '<div><i class="bi bi-clock me-1"></i>' . Html::encode($label) . ': '
                . '<span class="utc-time" data-utc="' . Html::encode($utc) . '"></span></div>';
        }

        return $lines === '' ? '' : '<div class="activity-meta">' . $lines . '</div>';
    }

    /**
     * The status of a record as its row draws it. Only a message id the
     * channel gave back says «Опубликовано»: a record whose time has come
     * without one is still standing in the queue, and its time is what the
     * schedule has for it, not what the channel holds.
     */
    public static function statusBadge(PublicationData $record, string $now): string
    {
        if ($record->telegramId !== null) {
            return '<span class="badge bg-success mt-2">Опубликовано</span>';
        }

        if ($record->publishedAt !== null && $record->publishedAt <= $now) {
            return '<span class="badge bg-warning text-dark mt-2">В очереди</span>';
        }

        return '<span class="badge bg-info mt-2">Запланировано</span>';
    }

    /**
     * The album of a saved record: its first pictures, as many as the settings
     * allow a record to show, and the count of the rest behind them.
     */
    public static function stackedImages(array $imageUrls): string
    {
        if ($imageUrls === []) {
            return '';
        }

        $limit = (int)Yii::$app->controller->publicationSettings()['imagesShownLimit'];
        $shown = array_slice($imageUrls, 0, $limit);
        $html = '<div class="stacked-images sm mt-2">';
        foreach ($shown as $url) {
            $html .= '<img src="' . Html::encode($url) . '" alt="Изображение публикации">';
        }
        $rest = count($imageUrls) - count($shown);
        if ($rest > 0) {
            $html .= '<span class="plus bg-danger">+' . $rest . '</span>';
        }
        $html .= '</div>';

        return $html;
    }
}
