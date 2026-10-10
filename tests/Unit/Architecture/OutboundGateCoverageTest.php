<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Domains\Messaging\OutboundVolumeGate;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every Core path that delivers a message to a contact spends outbound volume first. Two calls
 * deliver: a channel sender's `->deliver(` and a media dispatcher's `->ensureUploadedToChannel(`
 * with a target chat, which uploads and sends in one step. A file that makes either call has to be
 * decided here, which is the moment to ask whether it goes through {@see OutboundVolumeGate}.
 */
final class OutboundGateCoverageTest extends TestCase
{
    /**
     * What puts something in front of a contact or reaches an outside system for a flow: a channel
     * sender or uploader, a direct Telegram Bot API send, and a call transport.
     */
    private const string DELIVERY_CALL = '/->(deliver|ensureUploadedToChannel|upload|send(Message|Photo|Document|Video|Voice))\(|->execute\(\$request,/';

    /**
     * Files that deliver and spend the volume themselves, before the call: the text to find before it.
     *
     * @var array<string, string>
     */
    private const array GATED = [
        'app/Domains/Messaging/MessageSender.php'                        => 'volumeGate->admit',
        'app/Domains/Flow/Services/FlowMessageSender.php'                => 'volumeGate->admit',
        'app/Domains/Conversation/Services/ConversationReplyService.php' => 'volumeGate->admit',
        'app/Domains/Flow/Handlers/CallNodeHandler.php'                  => 'refuseWhenVolumeUsedUp(',
        'app/Domains/Flow/Call/CallTester.php'                           => 'quota->consume',
    ];

    /**
     * Files that deliver only on behalf of a gated file: a channel sender and its delivery actions are called
     * by MessageSender after it has spent the unit, and the media dispatcher by the two media paths above.
     *
     * @var list<string>
     */
    private const array BEHIND_THE_FUNNEL = [
        'app/Domains/Channels/Telegram/TelegramSender.php',
        'app/Domains/Channels/Telegram/TelegramTextDeliveryAction.php',
        'app/Domains/Channels/Telegram/TelegramPhotoDeliveryAction.php',
        'app/Domains/Channels/Telegram/TelegramDocumentDeliveryAction.php',
        'app/Domains/Channels/Telegram/TelegramVideoDeliveryAction.php',
        'app/Domains/Channels/Telegram/TelegramVoiceDeliveryAction.php',
        'app/Domains/Media/Services/MediaDispatcher.php',
    ];

    public function test_every_file_that_delivers_is_decided(): void
    {
        $undecided = array_values(array_diff(
            array_keys($this->filesThatDeliver()),
            array_keys(self::GATED),
            self::BEHIND_THE_FUNNEL,
        ));

        $this->assertSame(
            [],
            $undecided,
            'These files deliver to a contact but are in neither GATED nor BEHIND_THE_FUNNEL of ' . self::class . '. '
            . 'Spend the volume through ' . OutboundVolumeGate::class . ' before the delivery, or send through MessageSender.',
        );
    }

    public function test_gated_files_spend_the_volume_before_they_deliver(): void
    {
        $files = $this->filesThatDeliver();

        foreach (self::GATED as $path => $needle) {
            $this->assertArrayHasKey($path, $files, "{$path} no longer delivers; remove it from GATED.");

            $source = (string) file_get_contents(base_path($path));

            // Byte offsets on both sides: the delivery offsets come from preg_match.
            $this->assertSame(1, preg_match('/' . preg_quote($needle, '/') . '/', $source, $gateMatch, PREG_OFFSET_CAPTURE), "{$path} delivers without spending volume.");
            $gate = $gateMatch[0][1];

            $this->assertLessThan(
                $files[$path],
                $gate,
                "{$path} spends outbound volume after its first delivery call.",
            );
        }
    }

    public function test_listed_files_still_exist(): void
    {
        foreach ([...array_keys(self::GATED), ...self::BEHIND_THE_FUNNEL] as $path) {
            $this->assertFileExists(base_path($path));
        }
    }

    /**
     * @return array<string, int> path relative to the project => offset of the first delivery call
     */
    private function filesThatDeliver(): array
    {
        $found = [];

        foreach ((new Finder())->files()->in(base_path('app'))->name('*.php') as $file) {
            $source = $file->getContents();

            if (1 === preg_match(self::DELIVERY_CALL, $source, $match, PREG_OFFSET_CAPTURE)) {
                $found['app/' . str_replace('\\', '/', $file->getRelativePathname())] = $match[0][1];
            }
        }

        return $found;
    }
}
