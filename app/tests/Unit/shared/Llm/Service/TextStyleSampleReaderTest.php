<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
use app\shared\Llm\Service\TextStyleSampleReader;
use Codeception\Test\Unit;

final class TextStyleSampleReaderTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private string $_root = '';

    protected function _before(): void
    {
        parent::_before();

        $root = sys_get_temp_dir() . '/llm-sample-' . getmypid() . '-' . uniqid('', false);
        $this->_root = $root;
        mkdir($root, 0777, true);
    }

    protected function _after(): void
    {
        parent::_after();

        $this->purgeTree($this->_root);
    }

    private function purgeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->purgeTree($full);
                continue;
            }

            unlink($full);
        }

        rmdir($path);
    }

    /**
     * marker + ':' + 250 знаков 'а' — сообщение гарантированно проходит порог
     * образца по одиночке (MIN_SAMPLE_CHARS = 200) и остаётся различимым в
     * склеенном блоке.
     */
    private function long(string $marker): string
    {
        return $marker . ':' . str_repeat('а', 250);
    }

    private function writeFixture(array $messages): TextStyleSampleReader
    {
        mkdir($this->_root . '/' . self::STYLE, 0777, true);
        file_put_contents(
            $this->_root . '/' . self::STYLE . '/result.json',
            (string) json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE),
        );

        return new TextStyleSampleReader($this->_root);
    }

    public function testBothShapesOfTextReachTheBlock(): void
    {
        $reader = $this->writeFixture([
            ['text' => $this->long('старое')],
            ['text' => [['type' => 'bold', 'text' => $this->long('жирное')], ' ', $this->long('новое')]],
        ]);

        $sample = $reader->read(self::STYLE, 40000);

        $this->assertStringContainsString($this->long('старое'), $sample->block);
        $this->assertStringContainsString($this->long('жирное') . ' ' . $this->long('новое'), $sample->block);
    }

    public function testAnArrayGluesItsPartsAndSkipsWhatCarriesNoText(): void
    {
        $reader = $this->writeFixture([
            [
                'text' => [
                    'строкой',
                    ['type' => 'bold', 'text' => $this->long('меткой')],
                    ['type' => 'unknown'],
                    7,
                    null,
                ],
            ],
        ]);

        $block = $reader->read(self::STYLE, 40000)->block;

        $this->assertStringContainsString(
            'строкой' . $this->long('меткой'),
            $block,
            'Части склеиваются без разделителя, как они склеены в самом сообщении.',
        );
        $this->assertStringNotContainsString(
            'unknown',
            $block,
            'Часть без текста пропущена, а не подставлена пустой или своим типом.',
        );
    }

    public function testTheNewestMessageComesFirst(): void
    {
        $reader = $this->writeFixture([
            ['text' => $this->long('первое')],
            ['text' => $this->long('второе')],
            ['text' => $this->long('третье')],
        ]);

        $block = $reader->read(self::STYLE, 40000)->block;

        $this->assertLessThan(
            (int) mb_strpos($block, $this->long('второе')),
            (int) mb_strpos($block, $this->long('третье')),
            'Файл идёт от старых к новым, значит свежее в блоке стоит раньше.',
        );
        $this->assertLessThan(
            (int) mb_strpos($block, $this->long('первое')),
            (int) mb_strpos($block, $this->long('второе')),
        );
    }

    public function testTheCeilingCutsOnAMessageBoundary(): void
    {
        $one = mb_strlen($this->long('первое'));
        $reader = $this->writeFixture([
            ['text' => $this->long('первое')],
            ['text' => $this->long('второе')],
            ['text' => $this->long('третье')],
        ]);

        $sample = $reader->read(self::STYLE, $one * 2);

        $this->assertSame($one * 2, $sample->chars);
        $this->assertStringContainsString($this->long('третье'), $sample->block);
        $this->assertStringNotContainsString($this->long('первое'), $sample->block);
        $this->assertStringNotContainsString(
            'первое',
            $sample->block,
            'Сообщение не переносится в выборку серединой — ни хвоста, ни начала.',
        );
    }

    public function testWhitespaceInsideAMessageIsCompressed(): void
    {
        $reader = $this->writeFixture([
            ['text' => "первое\n\n   \t" . $this->long('хвост')],
            ['text' => $this->long('второе')],
        ]);

        $this->assertStringContainsString(
            'первое ' . $this->long('хвост'),
            $reader->read(self::STYLE, 40000)->block,
        );
    }

    public function testTheBlockNamesTheFolderAndCountsTheCharacters(): void
    {
        $reader = $this->writeFixture([['text' => $this->long('единственное')]]);

        $sample = $reader->read(self::STYLE, 40000);
        $expected = mb_strlen($this->long('единственное'));

        $this->assertSame(self::STYLE, $sample->styleName);
        $this->assertSame($expected, $sample->chars);
        $this->assertStringContainsString('Ватник в Америке', $sample->block);
        $this->assertStringContainsString((string) $expected, $sample->block);
        $this->assertStringContainsString('конец образца', $sample->block);
    }

    public function testHasAnswersForAFolderThatIsThereAndOneThatIsNot(): void
    {
        $reader = $this->writeFixture([['text' => $this->long('единственное')]]);

        $this->assertTrue($reader->has(self::STYLE));
        $this->assertFalse($reader->has('g_jobbot'));
        $this->assertFalse($reader->has('ТакойПапкиНет'));
    }

    public function testAnAbsentFolderIsRefusedByName(): void
    {
        $reader = new TextStyleSampleReader($this->_root);

        try {
            $reader->read('ТакойПапкиНет', 40000);
            $this->fail('Отказ на отсутствующую папку не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString('ТакойПапкиНет', $e->getMessage());
        }
    }

    public function testAFileThatIsNotJsonIsRefused(): void
    {
        mkdir($this->_root . '/' . self::STYLE, 0777, true);
        file_put_contents($this->_root . '/' . self::STYLE . '/result.json', 'не json');

        $reader = new TextStyleSampleReader($this->_root);

        try {
            $reader->read(self::STYLE, 40000);
            $this->fail('Отказ на неразбираемый файл не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString(self::STYLE, $e->getMessage());
        }
    }

    public function testAFileWithoutMessagesIsRefused(): void
    {
        mkdir($this->_root . '/' . self::STYLE, 0777, true);
        file_put_contents(
            $this->_root . '/' . self::STYLE . '/result.json',
            (string) json_encode(['version' => 1], JSON_UNESCAPED_UNICODE),
        );

        $reader = new TextStyleSampleReader($this->_root);

        try {
            $reader->read(self::STYLE, 40000);
            $this->fail('Отказ на файл без messages не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString('messages', $e->getMessage());
        }
    }

    public function testASampleShorterThanTheThresholdIsRefused(): void
    {
        $reader = $this->writeFixture([['text' => 'совсем коротко']]);

        $this->assertLessThan(TextStyleSampleReader::MIN_SAMPLE_CHARS, mb_strlen('совсем коротко'));
        try {
            $reader->read(self::STYLE, 40000);
            $this->fail('Отказ на короткий образец не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString(self::STYLE, $e->getMessage());
        }
    }
}
