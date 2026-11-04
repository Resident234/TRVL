<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Settings\Service;

use app\shared\Settings\Contract\PublicationSettingsRepositoryInterface;

/**
 * The settings table without PostgreSQL: the whole map is held in a property,
 * so a test can seed the values a real page would have read and look at what
 * the form left behind. A save writes every code it is given and keeps the
 * rest, which is what an UPSERT per code does.
 */
final class InMemorySettingsRepository implements PublicationSettingsRepositoryInterface
{
    /** @var array<string, string> code => stored value */
    public array $values;

    /** @var int how many times a save was asked for */
    public int $saves = 0;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @param array<string, string> $values
     */
    public function saveMany(array $values, string $now): void
    {
        $this->saves++;
        foreach ($values as $code => $value) {
            $this->values[$code] = $value;
        }
    }
}
