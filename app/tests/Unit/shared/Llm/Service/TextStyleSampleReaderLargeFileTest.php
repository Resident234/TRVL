<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Service\TextStyleSampleReader;
use Codeception\Test\Unit;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A large export cannot be decoded whole: the worker died with a 1 GB memory
 * limit on a 400 MB file, leaving style jobs running forever. The reader
 * indexes message offsets instead, draws messages randomly from the whole
 * file and decodes only the few that fit the character budget. A seeded
 * generator makes the draw repeatable here.
 */
final class TextStyleSampleReaderLargeFileTest extends Unit
{
    private const STYLE = 'Большой стиль';
    private const MESSAGES = 5000;
    private const CEILING = 5000;

    private string $_root = '';

    protected function _before(): void
    {
        parent::_before();

        $this->_root = sys_get_temp_dir() . '/llm-large-sample-' . getmypid() . '-' . uniqid('', false);
        mkdir($this->_root . '/' . self::STYLE, 0777, true);
    }

    protected function _after(): void
    {
        parent::_after();

        $entries = scandir($this->_root . '/' . self::STYLE);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($this->_root . '/' . self::STYLE . '/' . $entry);
                }
            }
        }
        rmdir($this->_root . '/' . self::STYLE);
        rmdir($this->_root);
    }

    private function reader(int $seed): TextStyleSampleReader
    {
        return new TextStyleSampleReader($this->_root, new Randomizer(new Mt19937($seed)));
    }

    private function writeExport(): void
    {
        $handle = fopen($this->_root . '/' . self::STYLE . '/result.json', 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create the sample fixture.');
        }

        fwrite($handle, "{\n \"name\": \"Образец\",\n \"messages\": [\n");
        for ($index = 1; $index <= self::MESSAGES; $index++) {
            $marker = 'M' . str_pad((string)$index, 5, '0', STR_PAD_LEFT);
            $message = [
                'id' => $index,
                'type' => 'message',
                'text' => $marker . ':' . str_repeat('а', 250),
                // A field of no interest to the reader, but large enough to
                // push the export past the streaming threshold.
                'photo' => ['file_id' => str_repeat('x', 8000)],
            ];
            $encoded = (string)json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            fwrite($handle, '  ' . str_replace("\n", "\n  ", $encoded) . ",\n");
        }
        fwrite($handle, " ]\n}\n");
        fclose($handle);
    }

    /**
     * @return int[]
     */
    private function markerNumbers(string $block): array
    {
        preg_match_all('/\bM(\d{5}):/u', $block, $matches);

        return array_map('intval', $matches[1]);
    }

    public function testTheSampleComesFromAllOverTheExportAndFitsTheBudget(): void
    {
        $this->writeExport();

        $sample = $this->reader(7321)->read(self::STYLE, self::CEILING);
        $numbers = $this->markerNumbers($sample->block);

        $this->assertGreaterThanOrEqual(TextStyleSampleReader::MIN_SAMPLE_CHARS, $sample->chars);
        $this->assertLessThanOrEqual(self::CEILING, $sample->chars);
        $this->assertNotEmpty($numbers);
        $this->assertStringContainsString('выбранные случайно из всего образца', $sample->block);
        $this->assertStringNotContainsString('от самых свежих к самым старым', $sample->block);

        // A draw from the whole file, not from its tail: the newest message
        // of the export is M05000 and almost never appears in a small sample.
        $this->assertTrue(min($numbers) < intdiv(self::MESSAGES, 2));
        $this->assertTrue(max($numbers) >= intdiv(self::MESSAGES, 2));
        $this->assertNotContains(self::MESSAGES, $numbers);
    }

    public function testAnotherSeedDrawsAnotherSliceOfTheSameExport(): void
    {
        $this->writeExport();

        $first = $this->markerNumbers($this->reader(11)->read(self::STYLE, self::CEILING)->block);
        $second = $this->markerNumbers($this->reader(99)->read(self::STYLE, self::CEILING)->block);

        $this->assertNotEmpty($first);
        $this->assertNotEmpty($second);
        $this->assertNotSame($first, $second);
    }
}
