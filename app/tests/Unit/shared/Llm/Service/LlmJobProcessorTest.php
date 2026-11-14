<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Service\LlmJobProcessor;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;

final class LlmJobProcessorTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private const PORTRAIT = 'Автор рубит предложения коротко, любит обращение «братишка».';

    private const ANSWER = '{"text":"Ну здравствуй, братишка.","entities":[{"type":"bold","offset":15,"length":8}]}';

    private const GROUP = '0123456789abcdef0123456789abcdef';

    private StyleSampleFolder $_folder;

    private FakeJobQueue $_queue;

    private LlmClientStub $_stub;

    protected function _before(): void
    {
        parent::_before();

        $this->_folder = StyleSampleFolder::create(self::STYLE);

        $this->_queue = new FakeJobQueue();
    }

    protected function _after(): void
    {
        $this->_folder->purge();
    }

    private function processor(array $answers, array $values = []): LlmJobProcessor
    {
        $this->_stub = new LlmClientStub($answers);

        $settings = new PublicationSettingsService(
            new InMemorySettingsRepository($values + ['activeTextStyle' => self::STYLE]),
            new ListedStylesStub([self::STYLE]),
        );

        return new LlmJobProcessor(
            $this->_queue,
            new LlmTextService($this->_stub, $settings, $this->_folder->reader()),
            $settings,
        );
    }

    private function job(int $id, string $styleName = self::STYLE): LlmJobData
    {
        return new LlmJobData(
            $id,
            LlmJobData::QUEUED,
            LlmJobData::KIND_STYLE,
            self::GROUP,
            0,
            $styleName,
            'Привет мир.',
            '[]',
        );
    }

    /**
     * gmdate пишет UTC, а strtotime читает строку в поясе машины. Единица теста —
     * контейнер с его UTC, но `UTC` в конце строки держит проверку верной и на
     * хосте в другом поясе.
     */
    private function secondsAgo(?string $horizon): int
    {
        $this->assertNotNull($horizon);

        return time() - strtotime((string) $horizon . ' UTC');
    }

    public function testAnEmptyQueueTouchesNothing(): void
    {
        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['done']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(0, $this->_stub->calls);
    }

    /**
     * Фикстура считает обе чистки ненулевыми числами, и здесь они стоят свои:
     * рабочий берёт их из ответа очереди, поэтому ноль, вписанный в статистику
     * дрейна напрямую, провалит этот тест, а не пройдёт незамеченным.
     */
    public function testTheDrainLocksWorksAndUnlocksInThatOrder(): void
    {
        $this->_queue->reclaimResult = 11;
        $this->_queue->purgeResult = 5;

        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertFalse($stats['locked']);
        $this->assertSame(
            ['acquireLock', 'reclaimStale', 'purgeFinished', 'claim', 'releaseLock'],
            $this->_queue->calls,
        );
        $this->assertSame(
            [LlmJobProcessor::LOCK_CODE, LlmJobProcessor::LOCK_CODE],
            $this->_queue->lockCodes,
        );
        $this->assertSame('llm_job', LlmJobProcessor::LOCK_CODE);
        $this->assertSame(11, $stats['reclaimed']);
        $this->assertSame(5, $stats['purged']);
    }

    public function testADeniedLockStopsTheDrainBeforeAnyWork(): void
    {
        $this->_queue->lockGranted = false;
        $this->_queue->queued[] = $this->job(1);

        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertTrue($stats['locked']);
        $this->assertSame(['acquireLock'], $this->_queue->calls);
        $this->assertSame(0, $this->_stub->calls);
    }

    public function testTheJobIsFinishedWithTheAnswerOfTwoCalls(): void
    {
        $this->_queue->queued[] = $this->job(7);

        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['done']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(2, $this->_stub->calls);
        $this->assertSame([], $this->_queue->queued);
        $this->assertSame(
            [['id' => 7, 'text' => 'Ну здравствуй, братишка.', 'formatting' => '[{"type":"bold","offset":15,"length":8}]']],
            $this->_queue->done,
        );
    }

    public function testTheStyleOfTheRowWinsOverTheActiveStyle(): void
    {
        $this->_queue->queued[] = $this->job(1);

        $stats = $this->processor([self::PORTRAIT, self::ANSWER], ['activeTextStyle' => ''])->drain();

        $this->assertSame(1, $stats['done']);
        $this->assertStringContainsString(
            'Разбор образца стиля «Ватник в Америке»',
            $this->_stub->seen[1][1]['content'],
        );
    }

    public function testARefusedPortraitMarksTheJobFailedAndTheDrainGoesOn(): void
    {
        $this->_queue->queued[] = $this->job(1);
        $this->_queue->queued[] = $this->job(2);

        // 1 primary + 3 fallback attempts all return empty portrait for job 1,
        // then portrait + answer for job 2.
        $stats = $this->processor([
            '', '', '', '',
            self::PORTRAIT, self::ANSWER,
        ])->drain();

        $this->assertSame(2, $stats['processed']);
        $this->assertSame(1, $stats['done']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $this->_queue->failed[0]['id']);
        $this->assertSame('Разбор образца пуст — стилизовать нечем.', $this->_queue->failed[0]['error']);
    }

    public function testAnUnreadableSampleMarksTheJobFailed(): void
    {
        $this->_queue->queued[] = $this->job(3, 'Нет такой папки');

        $stats = $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['done']);
        $this->assertSame(0, $this->_stub->calls);
        $this->assertStringContainsString('Нет такой папки', $this->_queue->failed[0]['error']);
    }

    public function testReclaimAsksForRowsOlderThanTwoTimeouts(): void
    {
        $this->processor([self::PORTRAIT, self::ANSWER], ['llmTimeoutSeconds' => '45'])->drain();

        $this->assertSame([], $this->_queue->done);
        $this->assertGreaterThanOrEqual(90, $this->secondsAgo($this->_queue->reclaimedBefore));
        $this->assertLessThan(95, $this->secondsAgo($this->_queue->reclaimedBefore));
    }

    public function testPurgeAsksForRowsFinishedThreeDaysAgo(): void
    {
        $this->assertSame(3, LlmJobProcessor::PURGE_AFTER_DAYS);

        $this->processor([self::PORTRAIT, self::ANSWER])->drain();

        $this->assertGreaterThanOrEqual(259200, $this->secondsAgo($this->_queue->purgedBefore));
        $this->assertLessThan(259205, $this->secondsAgo($this->_queue->purgedBefore));
    }
}
