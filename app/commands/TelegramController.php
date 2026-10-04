<?php

declare(strict_types=1);

namespace app\commands;

use app\shared\Publications\Infrastructure\TempImageCleanup;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Telegram\Infrastructure\TelegramApiException;
use app\shared\Telegram\Service\ChannelService;
use InvalidArgumentException;
use yii\console\Controller;
use yii\console\ExitCode;
use Yii;

/**
 * TRVL channel management via Telegram Bot API.
 */
final class TelegramController extends Controller
{
    public function __construct(
        $id,
        $module,
        private readonly ChannelService $channel,
        private readonly PublicationsService $publications,
        private readonly TempImageCleanup $tempImages,
        $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /**
     * Show current TRVL channel info (id, title, username, description).
     */
    public function actionIndex(): int
    {
        if (!$this->channel->isConfigured()) {
            $this->stderr("Telegram-бот не сконфигурирован: задайте TELEGRAM_BOT_TOKEN в .env\n");

            return ExitCode::CONFIG;
        }

        try {
            $info = $this->channel->channelInfo();
        } catch (TelegramApiException $e) {
            $this->stderr("Telegram API error [{$e->errorCode}]: {$e->getMessage()}\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Канал: @{$info->username} (#{$info->id})\n");
        $this->stdout("Название: {$info->title}\n");
        $this->stdout("Тип: {$info->type}\n");
        $this->stdout("Описание: {$info->description}\n");

        return ExitCode::OK;
    }

    /**
     * Set the TRVL channel description (slagon = description).
     *
     * @param string|null $description new description (0-255 chars); omit to clear
     */
    public function actionSetDescription(?string $description = null): int
    {
        try {
            $this->channel->updateDescription($description ?? '');
        } catch (InvalidArgumentException $e) {
            $this->stderr("Ошибка: {$e->getMessage()}\n");

            return ExitCode::DATAERR;
        } catch (TelegramApiException $e) {
            $this->stderr("Telegram API error [{$e->errorCode}]: {$e->getMessage()}\n");

            return ExitCode::UNSPECIFIED_ERROR;
        } catch (\RuntimeException $e) {
            $this->stderr("Ошибка: {$e->getMessage()}\n");

            return ExitCode::CONFIG;
        }

        $this->stdout("Описание канала обновлено.\n");

        return ExitCode::OK;
    }

    /**
     * Send a test text message to the channel.
     *
     * @param string $text message text
     * @param bool $pin whether to pin the sent message
     */
    public function actionTestPost(string $text, bool $pin = false): int
    {
        try {
            $messageId = $this->channel->publishText($text);
            if ($pin) {
                $this->channel->pinPost($messageId);
            }
        } catch (InvalidArgumentException $e) {
            $this->stderr("Ошибка: {$e->getMessage()}\n");

            return ExitCode::DATAERR;
        } catch (TelegramApiException $e) {
            $this->stderr("Telegram API error [{$e->errorCode}]: {$e->getMessage()}\n");

            return ExitCode::UNSPECIFIED_ERROR;
        } catch (\RuntimeException $e) {
            $this->stderr("Ошибка: {$e->getMessage()}\n");

            return ExitCode::CONFIG;
        }

        $this->stdout("Опубликовано, message_id={$messageId}\n");

        return ExitCode::OK;
    }

    /**
     * Send all due scheduled posts to the channel: posts with an empty
     * telegram_id whose publication time has already come, from the
     * smallest id to the biggest one.
     */
    public function actionPublishDue(): int
    {
        $stats = $this->publications->publishDue();

        $this->stdout(sprintf(
            "Processed: %d, published: %d, failed: %d\n",
            $stats['processed'],
            $stats['published'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Remove all pending soft-deleted records from the channel:
     * publications_deleted rows with an empty deleted_at are deleted
     * from Telegram by their telegram_id, then stamped with the
     * actual removal time.
     */
    public function actionDeleteDue(): int
    {
        $stats = $this->publications->deleteDue();

        $this->stdout(sprintf(
            "Processed: %d, deleted: %d, failed: %d\n",
            $stats['processed'],
            $stats['deleted'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Update all pending archived edits in the channel:
     * publications_edited rows with an empty edited_at get their
     * message content replaced with the record's text field, then
     * stamped with the actual update time.
     */
    public function actionEditDue(): int
    {
        $stats = $this->publications->editDue();

        $this->stdout(sprintf(
            "Processed: %d, edited: %d, failed: %d\n",
            $stats['processed'],
            $stats['edited'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Remove the pictures the publishing queue downloaded for its albums and
     * needs no longer: files named publication_img_* in the temp directory,
     * older than the age limit the sweep runs with.
     */
    public function actionCleanTemp(): int
    {
        $stats = $this->tempImages->run();

        $this->stdout(sprintf(
            "Processed: %d, removed: %d, failed: %d\n",
            $stats['processed'],
            $stats['removed'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
