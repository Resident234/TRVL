<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Infrastructure\TempImageCleanup;
use app\shared\Publications\Service\PublicationsService;
use Codeception\Test\Unit;

/**
 * How the publishing queue hands the album of a record to the channel: every
 * picture is fetched by the portal itself, whatever host it stands on, and goes
 * out as a file. A host is not trusted to serve the channel the same picture it
 * serves the portal — foto.awd.ru answers a plain link with WEBPAGE_MEDIA_EMPTY
 * while it answers the portal with a JPEG — and a download that failed leaves
 * the address in the album instead, so the channel still gets its own try.
 */
final class PublicationsServiceImageDownloadTest extends Unit
{
    /** The host the queue used to download from, and the only one it used to. */
    private const FORUM = 'https://forum.awd.ru/gallery/images/upload/c0e/423/c0e423a6b89eb30aa97a20beb4369a48.jpg';

    /** A host of the forum the channel cannot read by itself. */
    private const FOTO = 'http://foto.awd.ru/data/media/15/_09_614-1.jpg';

    /** A host that belongs to nobody in particular. */
    private const FOREIGN = 'https://img.example.org/photo/512.png';

    /** An address naming a page rather than a picture, behind which a picture stands. */
    private const PAGE = 'https://img.example.org/gallery/image_page.php?album_id=54903&image_id=2048754';

    /** An address naming no file at all. */
    private const NO_NAME = 'https://img.example.org/media/9xQvT1';

    /** An address whose extension is written in capitals. */
    private const CAPITALS = 'https://img.example.org/scan.JPG';

    private const PNG = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" . 'filler bytes';

    private const JPEG = "\xFF\xD8\xFF\xE0" . 'filler bytes';

    private PhotosChannelStub $_channel;

    protected function _before(): void
    {
        parent::_before();

        $this->_channel = new PhotosChannelStub();
    }

    protected function _after(): void
    {
        foreach ($this->_channel->downloads() as $path) {
            unlink($path);
        }

        parent::_after();
    }

    /**
     * Drains one pass of the queue over a record holding the given album.
     *
     * @param string[] $imageUrls
     */
    private function publish(?RecordingHttpClientStub $client, array $imageUrls): void
    {
        $repository = $this->createMock(PublicationRepositoryInterface::class);
        $repository
            ->method('findDueForPublishing')
            ->willReturn([new PublicationData(
                41,
                'Запись с альбомом',
                $imageUrls,
                null,
                '2026-10-04 08:50:00',
                '2026-10-04 08:00:00',
                '2026-10-04 08:00:00',
            )]);

        $service = new PublicationsService($repository, null, $this->_channel, null, null, $client);

        $this->assertSame(
            ['processed' => 1, 'published' => 1, 'failed' => 0],
            $service->publishDue(),
        );
    }

    public function testAPictureOfEveryHostIsFetchedHereAndGoesOutAsAFile(): void
    {
        $client = new RecordingHttpClientStub([
            self::FORUM => self::PNG,
            self::FOTO => self::PNG,
            self::FOREIGN => self::PNG,
        ]);

        $this->publish($client, [self::FORUM, self::FOTO, self::FOREIGN]);

        $this->assertSame([self::FORUM, self::FOTO, self::FOREIGN], $client->asked);

        $album = $this->_channel->firstAlbum();
        $this->assertCount(3, $album);
        foreach ($album as $photo) {
            // The name is the contract with the daily sweep: what the queue writes
            // under TempImageCleanup::PREFIX is what that sweep comes back for.
            $this->assertStringStartsWith(sys_get_temp_dir() . '/' . TempImageCleanup::PREFIX, $photo);
            $this->assertFileExists($photo);
            $this->assertSame(self::PNG, file_get_contents($photo));
        }
    }

    public function testAPictureThatWouldNotDownloadGoesOutAsItsAddress(): void
    {
        $client = new RecordingHttpClientStub([self::FOTO => self::PNG], [self::FOREIGN]);

        $this->publish($client, [self::FOREIGN, self::FOTO]);

        $album = $this->_channel->firstAlbum();
        $this->assertSame(self::FOREIGN, $album[0]);
        $this->assertFileExists($album[1]);
        $this->assertSame([self::FOREIGN, self::FOTO], $client->asked);
    }

    public function testTheNameOfADownloadFollowsTheBytesOfTheAnswerAndNotItsAddress(): void
    {
        $client = new RecordingHttpClientStub([
            self::PAGE => self::PNG,
            self::NO_NAME => self::JPEG,
            self::CAPITALS => self::JPEG,
        ]);

        $this->publish($client, [self::PAGE, self::NO_NAME, self::CAPITALS]);

        $album = $this->_channel->firstAlbum();
        // An address naming a page says nothing about the picture behind it, so
        // the bytes of the answer name the file.
        $this->assertStringEndsWith('.png', $album[0]);
        $this->assertStringEndsWith('.jpg', $album[1]);
        // An address that does name a picture keeps that name, lower cased.
        $this->assertStringEndsWith('.jpg', $album[2]);
    }

    public function testWithoutAnHttpClientTheAddressesGoAsTheyStand(): void
    {
        $this->publish(null, [self::FOTO, self::FOREIGN]);

        $this->assertSame([self::FOTO, self::FOREIGN], $this->_channel->firstAlbum());
    }
}
