<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Telegram\Infrastructure;

use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Infrastructure\NutgramChannelClient;
use app\shared\Telegram\Infrastructure\TelegramApiException;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\Response;
use ReflectionProperty;
use SergiX44\Nutgram\Testing\FakeNutgram;

/**
 * The keyboard an album gets after the album was sent.
 *
 * `sendMediaGroup` takes no reply markup, so the button of a part with pictures
 * goes to the first message of the group by a request of its own. What the API
 * answers for a message that already wears that keyboard — 400 "message is not
 * modified" — is a refusal of a change that needed no change, and the queue has
 * to hear it as the send it is: a record that keeps being told it failed asks
 * the channel for the same album on every run and never leaves «В очереди».
 */
final class NutgramChannelClientAlbumKeyboardTest extends Unit
{
    private const CHANNEL_ID = '-1001234567890';

    private const FIRST_MESSAGE_ID = 4242;

    private const NOT_MODIFIED = 'Bad Request: message is not modified: specified new '
        . 'message content and reply markup are exactly the same as a current content '
        . 'and reply markup of the message';

    private const ALBUM = [
        'https://awd.ru/images/upload/74e4d8969a363a41.jpg',
        'https://awd.ru/images/upload/cd7e7a632e0facd4.jpg',
    ];

    private const CAPTION = 'Отель в Антверпене. Центр. Scheldezicht';

    /**
     * The album the channel took: two messages, the first of them the one the
     * caption went into and the one the keyboard is asked for.
     */
    private static function albumSent(): Response
    {
        return new Response(200, [], json_encode([
            'ok' => true,
            'result' => [
                self::message(self::FIRST_MESSAGE_ID),
                self::message(self::FIRST_MESSAGE_ID + 1),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The answer of a refused edit, worded the way the API words it: a 400 whose
     * only content is the description.
     */
    private static function editRefused(string $description): Response
    {
        return new Response(400, [], json_encode([
            'ok' => false,
            'error_code' => 400,
            'description' => $description,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(int $messageId): array
    {
        return [
            'message_id' => $messageId,
            'date' => 1759680000,
            'chat' => [
                'id' => (int) self::CHANNEL_ID,
                'type' => 'channel',
                'title' => 'TRVL',
            ],
        ];
    }

    /**
     * The bot Nutgram answers fakes with: it keeps the requests it was given
     * and replies with the bodies handed to it here.
     *
     * @param Response[] $responses
     */
    private function fake(array $responses): FakeNutgram
    {
        return FakeNutgram::instance(responses: $responses);
    }

    /**
     * The client the channel sends through, with its own bot stood down for the
     * fake one this test answers with.
     */
    private function client(FakeNutgram $fake): NutgramChannelClient
    {
        $client = new NutgramChannelClient('42:fake-token');

        $bot = new ReflectionProperty(NutgramChannelClient::class, '_bot');
        $bot->setValue($client, $fake);

        return $client;
    }

    private function buttons(): LinkButtons
    {
        return LinkButtons::fromPairs(['Обсудить на форуме'], ['https://awd.ru/forum/441039']);
    }

    public function testAnAlbumAlreadyWearingTheKeyboardIsHandedBackAsSent(): void
    {
        $fake = $this->fake([
            self::albumSent(),
            self::editRefused(self::NOT_MODIFIED),
        ]);

        $result = $this->client($fake)->sendPhotoGroupMessage(
            self::CHANNEL_ID,
            self::ALBUM,
            self::CAPTION,
            new MessageEntities(),
            $this->buttons(),
        );

        $this->assertSame(self::FIRST_MESSAGE_ID, $result->messageId);
        $this->assertSame(1759680000, $result->date);

        $this->assertCount(2, $fake->getRequestHistory());
    }

    public function testTheKeyboardIsStillAskedForAfterTheAlbumWasSent(): void
    {
        $fake = $this->fake([
            self::albumSent(),
            self::editRefused(self::NOT_MODIFIED),
        ]);

        $this->client($fake)->sendPhotoGroupMessage(
            self::CHANNEL_ID,
            self::ALBUM,
            self::CAPTION,
            new MessageEntities(),
            $this->buttons(),
        );

        $uris = array_map(
            static fn (array $history): string => (string) $history['request']->getUri(),
            $fake->getRequestHistory(),
        );

        $this->assertStringEndsWith('sendMediaGroup', $uris[0]);
        $this->assertStringEndsWith('editMessageReplyMarkup', $uris[1]);
    }

    public function testAnAlbumSentWithoutButtonsIsNotEditedAtAll(): void
    {
        $fake = $this->fake([self::albumSent()]);

        $result = $this->client($fake)->sendPhotoGroupMessage(
            self::CHANNEL_ID,
            self::ALBUM,
            self::CAPTION,
            new MessageEntities(),
            LinkButtons::empty(),
        );

        $this->assertSame(self::FIRST_MESSAGE_ID, $result->messageId);
        $this->assertCount(1, $fake->getRequestHistory());
    }

    public function testAnEditRefusedForAnythingElseStillRaises(): void
    {
        $fake = $this->fake([
            self::albumSent(),
            self::editRefused('Bad Request: chat not found'),
        ]);

        $this->expectException(TelegramApiException::class);
        $this->expectExceptionMessage('chat not found');

        $this->client($fake)->sendPhotoGroupMessage(
            self::CHANNEL_ID,
            self::ALBUM,
            self::CAPTION,
            new MessageEntities(),
            $this->buttons(),
        );
    }

    public function testTheWordingOfARefusedChangeIsKnownAndTheOthersAreNot(): void
    {
        $this->assertTrue((new TelegramApiException(self::NOT_MODIFIED, 400))->isMessageUnchanged());
        $this->assertFalse((new TelegramApiException('Bad Request: chat not found', 400))->isMessageUnchanged());
        $this->assertFalse((new TelegramApiException(self::NOT_MODIFIED, 400))->isMessageGone());
    }
}
