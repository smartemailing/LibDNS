<?php declare(strict_types=1);

namespace DaveRandom\LibDNS\Encoding;

use DaveRandom\LibDNS\Messages\Message;
use DaveRandom\LibDNS\Records\QuestionRecord;
use DaveRandom\LibDNS\Records\ResourceRecord;
use function DaveRandom\LibDNS\encode_domain_name;

final class Encoder
{
    private $resourceDataEncoder;

    private function encodeHeader(EncodingContext $ctx, Message $message, int $qdCount, int $anCount, int $nsCount, int $arCount): string
    {
        return \pack(
            'n6',
            $message->getId(),
            $message->getFlags() | ($message->getOpCode() << 11) | ($ctx->isTruncated << 9) | $message->getResponseCode(),
            $qdCount,
            $anCount,
            $nsCount,
            $arCount
        );
    }

    private function encodeQuestionRecord(EncodingContext $ctx, QuestionRecord $record): bool
    {
        encode_domain_name($ctx, $record->getName());
        $ctx->appendData(\pack('n2', $record->getType(), $record->getClass()));

        if ($ctx->isDataLengthExceeded()) {
            $ctx->isTruncated = true;
            return false;
        }

        $ctx->commitPendingData();
        return true;
    }

    private function encodeResourceRecord(EncodingContext $ctx, ResourceRecord $record): bool
    {
        encode_domain_name($ctx, $record->getName());
        $ctx->appendData(\pack('n2N', $record->getType(), $record->getClass(), $record->getTTL()));

        $ctx->beginRecordData();
        $this->resourceDataEncoder->encode($ctx, $record->getData());

        if ($ctx->isDataLengthExceeded()) {
            $ctx->isTruncated = true;
            return false;
        }

        $ctx->commitPendingData();
        return true;
    }

    public function __construct()
    {
        $this->resourceDataEncoder = new ResourceDataEncoder();
    }

    /**
     * Encode a Message to raw network data
     *
     * @param Message $message  The Message to encode
     * @param bool $compress Enable message compression
     * @return string
     */
    public function encode(Message $message, int $options = 0): string
    {
        $ctx = new EncodingContext($options);
        $qdCount = $anCount = $nsCount = $arCount = 0;

        foreach ($message->getQuestionRecords() as $record) {
            if (!$this->encodeQuestionRecord($ctx, $record)) {
                goto done;
            }

            $qdCount++;
        }

        foreach ($message->getAnswerRecords() as $record) {
            if (!$this->encodeResourceRecord($ctx, $record)) {
                goto done;
            }

            $anCount++;
        }

        foreach ($message->getAuthorityRecords() as $record) {
            if (!$this->encodeResourceRecord($ctx, $record)) {
                goto done;
            }

            $nsCount++;
        }

        foreach ($message->getAdditionalRecords() as $record) {
            if (!$this->encodeResourceRecord($ctx, $record)) {
                goto done;
            }

            $arCount++;
        }

        done:
        $packet = $this->encodeHeader($ctx, $message, $qdCount, $anCount, $nsCount, $arCount) . $ctx->data;

        if (!($options & EncodingOptions::FORMAT_TCP)) {
            \assert(
                \strlen($packet) <= 512,
                new \Error('UDP packet exceeds 512 byte limit: got ' . \strlen($packet) . ' bytes')
            );

            return $packet;
        }

        \assert(
            \strlen($packet) <= 65535,
            new \Error('TCP packet exceeds 65535 byte limit: got ' . \strlen($packet) . ' bytes')
        );

        return $packet . \pack('n', \strlen($packet));
    }
}
