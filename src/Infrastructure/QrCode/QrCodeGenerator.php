<?php

declare(strict_types=1);

namespace App\Infrastructure\QrCode;

use App\Application\Port\QrCodeGeneratorInterface;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\Result\SvgResult;
use Endroid\QrCode\Writer\SvgWriter;

final readonly class QrCodeGenerator implements QrCodeGeneratorInterface
{
    public function __construct(private SvgWriter $writer) {}

    #[\Override]
    public function dataUri(string $url): string
    {
        /** @var SvgResult $result */
        $result = $this->writer->write(new QrCode(
            data: $url,
            errorCorrectionLevel: ErrorCorrectionLevel::Quartile,
            // One SVG unit per module with a four-module quiet zone.
            size: 1,
            margin: 4,
            roundBlockSizeMode: RoundBlockSizeMode::Enlarge,
        ));
        $result->getXml()->addAttribute(qualifiedName: 'shape-rendering', value: 'crispEdges');

        return $result->getDataUri();
    }
}
