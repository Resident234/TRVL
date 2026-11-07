<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Service\TextStyleSampleReader;

/**
 * A styles catalog of one folder, written for the duration of one test class.
 * The messages are long enough that the sample of any ceiling the settings
 * allow passes the 200 character threshold.
 */
final class StyleSampleFolder
{
    public function __construct(public readonly string $path)
    {
    }

    public static function create(string $styleName): self
    {
        $root = sys_get_temp_dir() . '/llm-styles-' . getmypid() . '-' . uniqid('', false);
        mkdir($root . '/' . $styleName, 0777, true);

        $messages = [];
        for ($index = 0; $index < 4; $index++) {
            $messages[] = ['text' => $index . ':' . str_repeat('а', 900)];
        }

        file_put_contents(
            $root . '/' . $styleName . '/result.json',
            (string) json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE),
        );

        return new self($root);
    }

    public function reader(): TextStyleSampleReader
    {
        return new TextStyleSampleReader($this->path);
    }

    public function purge(): void
    {
        $file = $this->path . '/Ватник в Америке/result.json';
        if (is_file($file)) {
            unlink($file);
        }

        rmdir($this->path . '/Ватник в Америке');
        rmdir($this->path);
    }
}
