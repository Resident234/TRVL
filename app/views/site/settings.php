<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string|null $channelDescription */
/** @var \app\shared\Telegram\Dto\PublishedDescriptionData[] $publishedDescriptions */
/** @var array<string, string> $settings */
/** @var int $partsOffsetMin */
/** @var string $cronSchedule */

use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Service\ChannelService;
use yii\helpers\Html;

$this->title = 'Настройки публикаций';
$schema = PublicationSettingsService::schema();
// The lower bound of the gap between the parts is the cron of the publishing
// task, not the number written in the schema.
$bounds = [];
foreach ($schema as $code => $definition) {
    // Only a number has a lower bound to show; a format or an order is
    // picked from a list.
    if ($definition['type'] !== 'int') {
        continue;
    }

    $bounds[$code] = $code === 'partsOffsetMinutes'
        ? $partsOffsetMin
        : (int)$definition['min'];
}
?>
<!-- Row start -->
<div class="row">
    <div class="col-xxl-7 col-sm-12 col-12">

        <!-- Channel description management -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Описание канала</h5>
            </div>
            <div class="card-body">
                <form method="post" action="<?= \yii\helpers\Url::to(['site/channel-description']) ?>">
                    <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>"
                           value="<?= Yii::$app->request->csrfToken ?>">

                    <!-- Description input field -->
                    <div class="mb-3">
                        <textarea class="form-control" id="channelDescriptionInput" name="channelDescription"
                                  rows="4"
                                  maxlength="<?= ChannelService::DESCRIPTION_MAX_LENGTH ?>"
                                  aria-label="Описание канала"
                                  placeholder="Введите описание канала TRVL"><?= Html::encode($channelDescription ?? 'TRVL — канал о путешествиях и приключениях. Маршруты, лайфхаки и вдохновение для ваших странствий.') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check2 me-1"></i>Сохранить описание
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Сбросить
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-footer bg-transparent">
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Описание будет применено к каналу TRVL
                    </small>
                    <span class="badge bg-primary-subtle text-primary rounded-pill px-3"><?= ChannelService::DESCRIPTION_MAX_LENGTH ?> символов</span>
                </div>
            </div>
        </div>

        <!-- Publications tunables -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Публикации</h5>
            </div>
            <div class="card-body">
                <form method="post" action="<?= \yii\helpers\Url::to(['site/settings-save']) ?>">
                    <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>"
                           value="<?= Yii::$app->request->csrfToken ?>">

                    <?php foreach ($schema as $code => $definition): ?>
                        <div class="mb-3">
                            <label class="form-label" for="setting-<?= $code ?>">
                                <?= Html::encode($definition['label']) ?>
                            </label>
                            <?php if ($definition['type'] === 'int'): ?>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="setting-<?= $code ?>"
                                           name="settings[<?= $code ?>]"
                                           value="<?= (int)$settings[$code] ?>"
                                           min="<?= $bounds[$code] ?>" max="<?= (int)$definition['max'] ?>"
                                           step="1" required>
                                    <span class="input-group-text"><?= Html::encode($definition['unit']) ?></span>
                                </div>
                            <?php elseif ($definition['type'] === 'date'): ?>
                                <select class="form-select" id="setting-<?= $code ?>" name="settings[<?= $code ?>]">
                                    <?php foreach (PublicationSettingsService::dateOptions() as $value => $label): ?>
                                        <option value="<?= Html::encode($value) ?>" <?= $settings[$code] === $value ? 'selected' : '' ?>>
                                            <?= Html::encode($label) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            <?php else: ?>
                                <select class="form-select" id="setting-<?= $code ?>" name="settings[<?= $code ?>]">
                                    <?php foreach (PublicationSettingsService::orderOptions() as $value => $label): ?>
                                        <option value="<?= Html::encode($value) ?>" <?= $settings[$code] === $value ? 'selected' : '' ?>>
                                            <?= Html::encode($label) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            <?php endif ?>
                            <small class="text-muted d-block mt-1"><?= Html::encode($definition['hint']) ?>
                                <?php if ($code === 'partsOffsetMinutes'): ?>
                                    Меньше <?= $partsOffsetMin ?> минут задать нельзя: раз в столько задача
                                    за очередью смотрит, что пора публиковать.
                                <?php endif ?>
                            </small>
                        </div>
                    <?php endforeach ?>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2 me-1"></i>Сохранить настройки
                    </button>
                </form>
            </div>
        </div>

    </div>
    <div class="col-xxl-5 col-sm-12 col-12">

        <!-- Description archive -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Архив описаний</h5>
            </div>
            <div class="card-body pt-0">
                <?php if ($publishedDescriptions === []): ?>
                    <p class="text-muted small mb-0 py-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Описания пока не публиковались.
                    </p>
                <?php else: ?>
                    <div class="scroll300">
                        <ul class="list-group">
                            <?php foreach ($publishedDescriptions as $publishedDescription): ?>
                                <?php $isActive = $publishedDescription->publishedTo === null; ?>
                                <li class="list-group-item d-flex align-items-center gap-3">
                                    <div class="icon-box md <?= $isActive
                                        ? 'bg-primary-subtle text-primary rounded-4'
                                        : 'bg-secondary-subtle text-secondary rounded-4' ?>">
                                        <i class="bi bi-quote"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="small mb-1"><?= Html::encode($publishedDescription->description !== '' ? $publishedDescription->description : '—') ?></div>
                                        <small class="text-muted">с <?= Yii::$app->formatter->asDatetime($publishedDescription->publishedFrom, 'php:' . $settings['dateFormat'], $this->context->getUserDisplayTimezone()) ?><?= $isActive ? '' : ' по ' . Yii::$app->formatter->asDatetime($publishedDescription->publishedTo, 'php:' . $settings['dateFormat'], $this->context->getUserDisplayTimezone()) ?></small>
                                    </div>
                                    <span class="badge <?= $isActive
                                        ? 'bg-success-subtle text-success rounded-pill py-1 px-3'
                                        : 'bg-secondary-subtle text-secondary rounded-pill py-1 px-3' ?>">
                                        <?= $isActive ? 'Активен' : 'Архив' ?>
                                    </span>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    </div>
                <?php endif ?>
            </div>
        </div>

        <!-- Protocol limits of Telegram -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Лимиты Telegram</h5>
            </div>
            <div class="card-body pt-0">
                <p class="text-muted small mb-3">
                    <i class="bi bi-lock me-1"></i>
                    Эти числа задаёт сам Telegram — портал показывает их,
                    но не может изменить.
                </p>
                <table class="table table-sm align-middle mb-0">
                    <?php foreach (PublicationSettingsService::telegramLimits() as $limit): ?>
                        <tr>
                            <td class="text-muted ps-0"><?= Html::encode($limit['label']) ?></td>
                            <td class="text-end pe-0 fw-bold"><?= (int)$limit['value'] ?> <?= Html::encode($limit['unit']) ?></td>
                        </tr>
                    <?php endforeach ?>
                </table>
            </div>
        </div>

        <!-- Publishing task -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">Очередь публикации</h5>
            </div>
            <div class="card-body pt-0">
                <p class="text-muted small mb-3">
                    <i class="bi bi-lock me-1"></i>
                    Расписание задачи, которая отправляет намеченное, живёт в
                    конфиге крона контейнера и со страницы не правится.
                </p>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">publish-due</span>
                    <code><?= $cronSchedule !== '' ? Html::encode($cronSchedule) : 'не задано' ?></code>
                </div>
            </div>
        </div>

    </div>
</div>
<!-- Row end -->
