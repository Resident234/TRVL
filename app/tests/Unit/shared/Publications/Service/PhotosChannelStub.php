<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;

/**
 * The channel of a publication that carries an album. It keeps the media list
 * every publishPhotos() call was handed, so a test reads what the queue put in
 * front of Telegram: the file path of a picture it downloaded itself, or the
 * address of one it could not fetch and left for the channel to try.
 */
final class PhotosChannelStub
{
    /** @var array<int, array{text: string, photos: string[]}> what each send carried */
    public array $sends = [];

    public function publishPhotos(
        string $text,
        array $photoUrls,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): int {
        $this->sends[] = ['text' => $text, 'photos' => array_values($photoUrls)];

        return 5000 + count($this->sends);
    }

    /**
     * The photos of the first send, so a test speaks of the album the channel saw.
     *
     * @return string[]
     */
    public function firstAlbum(): array
    {
        return $this->sends[0]['photos'] ?? [];
    }

    /**
     * The pictures left in the temp directory by these sends: a test removes
     * them once it has read them.
     *
     * @return string[]
     */
    public function downloads(): array
    {
        $tempDir = sys_get_temp_dir();
        $paths = [];
        foreach ($this->sends as $send) {
            foreach ($send['photos'] as $photo) {
                if (str_starts_with($photo, $tempDir) && file_exists($photo)) {
                    $paths[] = $photo;
                }
            }
        }

        return $paths;
    }
}
