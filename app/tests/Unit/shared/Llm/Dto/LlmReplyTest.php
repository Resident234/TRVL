<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Dto;

use app\shared\Llm\Dto\LlmReply;
use Codeception\Test\Unit;

final class LlmReplyTest extends Unit
{
    /**
     * @param array<mixed> $decoded
     */
    private function reply(array $decoded): ?LlmReply
    {
        return LlmReply::fromApi($decoded);
    }

    public function testReadsTheFirstChoiceAndTheUsage(): void
    {
        $reply = $this->reply([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => "Готово.\nТекст."], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1234, 'completion_tokens' => 56],
        ]);

        $this->assertNotNull($reply, 'Ответ с сообщением обязан разобратьcя.');
        $this->assertSame("Готово.\nТекст.", $reply->text);
        $this->assertSame('stop', $reply->finishReason);
        $this->assertSame(1234, $reply->promptTokens);
        $this->assertSame(56, $reply->completionTokens);
    }

    public function testAnEmptyContentIsStillAnAnswerOfItsOwn(): void
    {
        $reply = $this->reply(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']]]);

        $this->assertNotNull($reply);
        $this->assertSame('', $reply->text);
        $this->assertSame('length', $reply->finishReason);
        $this->assertSame(0, $reply->promptTokens);
        $this->assertSame(0, $reply->completionTokens);
    }

    public function testGivesUpQuietlyOnAPayloadWithoutAChoice(): void
    {
        $this->assertNull($this->reply([]));
        $this->assertNull($this->reply(['choices' => []]));
        $this->assertNull($this->reply(['choices' => [['message' => []]]]));
        $this->assertNull($this->reply(['choices' => [['message' => ['content' => null]]]]));
        $this->assertNull($this->reply(['error' => ['message' => 'All 3 routed attempt(s) failed']]));
    }

    public function testTreatsANonStringContentAsNoAnswer(): void
    {
        $this->assertNull($this->reply(['choices' => [['message' => ['content' => 42]]]]));
        $this->assertNull($this->reply([
            'choices' => [['message' => ['content' => ['text' => 'массив вместо строки']]]],
        ]));
    }

    public function testKeepsAStreamAnswerOfTheReasoningModel(): void
    {
        // The proxy answers a `reasoning` model with its thinking in a separate field;
        // only `content` may reach the form, and the reason it stopped has to survive.
        $reply = $this->reply([
            'choices' => [
                ['message' => ['content' => 'Ответ.'], 'finish_reason' => 'stop'],
                'reasoning' => 'Длинные рассуждения…',
            ],
        ]);

        $this->assertNotNull($reply);
        $this->assertSame('Ответ.', $reply->text);
    }
}
