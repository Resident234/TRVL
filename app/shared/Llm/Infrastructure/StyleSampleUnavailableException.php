<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use RuntimeException;

/**
 * The sample could not be assembled: the folder of the chosen style is not
 * there, its export does not read, or what reads is too short to call a style.
 * The sentence names the folder, because that is what the person at the form
 * has to go and look at.
 */
final class StyleSampleUnavailableException extends RuntimeException
{
}
