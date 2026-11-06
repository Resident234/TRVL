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
        $this->assertStringContainsString(
            "\n\n---\n\n",
            $sample->block,
            'Заголовок обещает модели разделитель; склейка обязана его печатать, а не склеивать подряд.',
        );
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
                    ['type' => 'italic', 'text' => 42],
                    null,
                ],
            ],
        ]);

        $sample = $reader->read(self::STYLE, 40000);

        $this->assertStringContainsString(
            'строкой' . $this->long('меткой'),
            $sample->block,
            'Части склеиваются без разделителя, как они склеены в самом сообщении.',
        );
        $this->assertStringNotContainsString(
            'unknown',
            $sample->block,
            'Часть без текста пропущена, а не подставлена пустой или своим типом.',
        );
        // Число знаков — единственный страж для частей-чисел: каст `(string)` дописал бы
        // «7» и «42» в склейку, и проверка длины поймала бы это, а проверка на вхождение
        // цифры могла бы столкнуться с самой цифрой в заголовке блока.
        $this->assertSame(mb_strlen('строкой' . $this->long('меткой')), $sample->chars);
    }

    public function testTheNewestMessageComesFirst(): void
    {
        $reader = $this->writeFixture([
            ['text' => $this->long('первое')],
            ['text' => $this->long('второе')],
            ['text' => $this->long('третье')],
        ]);

        $block = $reader->read(self::STYLE, 40000)->block;
        $oldest = mb_strpos($block, $this->long('первое'));
        $middle = mb_strpos($block, $this->long('второе'));
        $newest = mb_strpos($block, $this->long('третье'));

        // Без этих трёх `mb_strpos() === false` превращался бы в `0` после каста, и
        // пропущенное сообщение выглядело бы самым свежим.
        $this->assertNotFalse($oldest, 'Самое старое сообщение обязано влезть в выборку.');
        $this->assertNotFalse($middle);
        $this->assertNotFalse($newest);
        $this->assertLessThan($middle, $newest, 'Файл идёт от старых к новым, значит свежее в блоке стоит раньше.');
        $this->assertLessThan($oldest, $middle);
    }

    public function testTheCeilingCutsOnAMessageBoundary(): void
    {
        $one = mb_strlen($this->long('первое'));
        $reader = $this->writeFixture([
            ['text' => $this->long('первое')],
            ['text' => $this->long('второе')],
            ['text' => $this->long('третье')],
        ]);

        // Потолок не кратен сообщению: два сообщения влезают, а остаток (100 знаков) —
        // нет. Реализация, которая добивает потолок серединой третьего, здесь отдала бы
        // `$one * 2 + 100` и кусок «первое:…»; граница по сообщению отдаёт `$one * 2`.
        $sample = $reader->read(self::STYLE, $one * 2 + 100);

        $this->assertSame($one * 2, $sample->chars);
        $this->assertStringContainsString($this->long('третье'), $sample->block);
        $this->assertStringContainsString($this->long('второе'), $sample->block);
        $this->assertStringNotContainsString($this->long('первое'), $sample->block);
        $this->assertStringNotContainsString(
            'первое',
            $sample->block,
            'Сообщение не переносится в выборку серединой — ни хвоста, ни начала.',
        );
    }

    public function testAMessageThatDoesNotFitEndsTheSampleRightThere(): void
    {
        $one = mb_strlen($this->long('новое'));
        $reader = $this->writeFixture([
            ['text' => $this->long('старое')],
            ['text' => str_repeat('д', 1000)],
            ['text' => $this->long('новое')],
        ]);

        // По спецификации выборка «обрывается на границе сообщения». Значит сообщение,
        // которое не влезает в остаток потолка, обрывает выборку, а не перескакивается:
        // самое старое «старое» в блок не попадает. Реализация на `continue`
        // отдала бы `$one * 2`.
        $sample = $reader->read(self::STYLE, $one * 2 + 100);

        $this->assertSame($one, $sample->chars);
        $this->assertStringContainsString($this->long('новое'), $sample->block);
        $this->assertStringNotContainsString('старое', $sample->block);
        $this->assertStringNotContainsString('ддд', $sample->block);
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
            $this->assertStringContainsString(
                (string) TextStyleSampleReader::MIN_SAMPLE_CHARS,
                $e->getMessage(),
                'Отказ называет порог, иначе человек не поймёт, до скольких знаков доращивать папку.',
            );
        }
    }

    public function testASampleOfExactlyTheThresholdIsAccepted(): void
    {
        $reader = $this->writeFixture([['text' => str_repeat('а', TextStyleSampleReader::MIN_SAMPLE_CHARS)]]);

        // Порог входит в выборку: реализация на `<=` отказала бы ровно на 200 знаках.
        $sample = $reader->read(self::STYLE, 40000);

        $this->assertSame(TextStyleSampleReader::MIN_SAMPLE_CHARS, $sample->chars);
        $this->assertStringContainsString(str_repeat('а', 200), $sample->block);
    }
}
