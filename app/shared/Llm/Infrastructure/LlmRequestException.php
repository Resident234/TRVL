<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use RuntimeException;

/**
 * Why a call to the proxy did not bring an answer: the key was refused, the
 * address did not answer, the budget ran out, or the payload had no message in
 * it. The sentence is written for the person standing at the form, and the form
 * shows it unchanged.
 */
final class LlmRequestException extends RuntimeException
{
}
