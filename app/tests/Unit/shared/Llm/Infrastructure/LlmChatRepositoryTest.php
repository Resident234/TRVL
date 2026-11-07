<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Infrastructure;

use app\shared\Llm\Dto\LlmChatMessageData;
use app\shared\Llm\Infrastructure\LlmChatRepository;
use Codeception\Test\Unit;
use Yii;

/**
 * The conversation table: a reply goes in, the window of replies comes back in the
 * order the block shows it, and one part's history never brings another part's.
 */
final class LlmChatRepositoryTest extends Unit
{
    private const GROUP = '0123456789abcdef0123456789abcdef';

    private const OTHER_GROUP = 'fedcba9876543210fedcba9876543210';

    private LlmChatRepository $_repository;

    protected function _before(): void
    {
        parent::_before();

        Yii::$app->getDb()
            ->createCommand('TRUNCATE TABLE {{%llm_chat_message}}')
            ->execute();
        $this->_repository = new LlmChatRepository(Yii::$app->getDb());
    }

    private function append(string $role, string $content, int $order = 0): int
    {
        return $this->_repository->append(
            new LlmChatMessageData(0, self::GROUP, $order, $role, $content),
            '2026-11-05 10:00:00',
        );
    }

    public function testAppendReturnsAnIdAndReadsTheRowBack(): void
    {
        $id = $this->append(LlmChatMessageData::ROLE_USER, 'сократи текст');

        $rows = $this->_repository->latest(self::GROUP, 0, 10);

        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]->id);
        $this->assertSame(self::GROUP, $rows[0]->partGroupId);
        $this->assertSame(0, $rows[0]->partOrder);
        $this->assertSame(LlmChatMessageData::ROLE_USER, $rows[0]->role);
        $this->assertSame('сократи текст', $rows[0]->content);
        $this->assertNull($rows[0]->replacementText);
        $this->assertNull($rows[0]->replacementFormatting);
        $this->assertSame('2026-11-05 10:00:00', $rows[0]->createdAt);
    }

    public function testAReplyCarriesTheReplacementItOffered(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'сократи текст');
        $this->_repository->append(
            new LlmChatMessageData(
                0,
                self::GROUP,
                0,
                LlmChatMessageData::ROLE_ASSISTANT,
                'Коротко: братишка, мы в Америке.',
                'Коротко: братишка, мы в Америке.',
                '[{"type":"bold","offset":8,"length":9}]',
            ),
            '2026-11-05 10:01:00',
        );

        $rows = $this->_repository->latest(self::GROUP, 0, 10);

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->replacementText);
        $this->assertSame('Коротко: братишка, мы в Америке.', $rows[1]->replacementText);
        $this->assertJsonStringEqualsJsonString(
            '[{"type":"bold","offset":8,"length":9}]',
            $rows[1]->replacementFormatting,
            'Метки читаются списком: jsonb переставляет ключи и расставляет пробелы.',
        );
    }

    public function testLatestReturnsTheWindowOldestFirst(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'первая');
        $this->append(LlmChatMessageData::ROLE_ASSISTANT, 'вторая');
        $this->append(LlmChatMessageData::ROLE_USER, 'третья');

        $rows = $this->_repository->latest(self::GROUP, 0, 10);

        $this->assertSame(['первая', 'вторая', 'третья'], array_map(
            static fn (LlmChatMessageData $row): string => $row->content,
            $rows,
        ));
    }

    public function testLatestCutsTheOlderRepliesOffTheLimit(): void
    {
        for ($index = 1; $index <= 5; $index++) {
            $this->append(LlmChatMessageData::ROLE_USER, 'реплика ' . $index);
        }

        $rows = $this->_repository->latest(self::GROUP, 0, 2);

        $this->assertSame(['реплика 4', 'реплика 5'], array_map(
            static fn (LlmChatMessageData $row): string => $row->content,
            $rows,
        ));
    }

    public function testLatestOfAnEmptyHistoryIsEmpty(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'чужая реплика', 7);

        $this->assertSame([], $this->_repository->latest(self::GROUP, 0, 10));
    }

    public function testThePartsOfOneGroupDoNotShareAHistory(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'первая часть', 0);
        $this->append(LlmChatMessageData::ROLE_USER, 'вторая часть', 1);

        $first = $this->_repository->latest(self::GROUP, 0, 10);
        $second = $this->_repository->latest(self::GROUP, 1, 10);

        $this->assertCount(1, $first);
        $this->assertSame('первая часть', $first[0]->content);
        $this->assertCount(1, $second);
        $this->assertSame('вторая часть', $second[0]->content);
    }

    public function testAnotherGroupKeepsItsHistoryApart(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'своей группы');
        $this->_repository->append(
            new LlmChatMessageData(0, self::OTHER_GROUP, 0, LlmChatMessageData::ROLE_USER, 'чужой группы'),
            '2026-11-05 10:00:00',
        );

        $rows = $this->_repository->latest(self::GROUP, 0, 10);

        $this->assertCount(1, $rows);
        $this->assertSame('своей группы', $rows[0]->content);
    }

    public function testAskingForAnEmptyGroupShowsNothing(): void
    {
        $this->append(LlmChatMessageData::ROLE_USER, 'без группы');

        $this->assertSame([], $this->_repository->latest('', 0, 10));
    }
}
