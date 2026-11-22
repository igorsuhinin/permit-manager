<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\Configuration\Settings;
use App\Application\Port\QrCodeGeneratorInterface;
use App\Application\View\PrintablePermit;
use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Service\PermitSigner;

final readonly class PrintablePermitFactory
{
    public function __construct(
        private PermitSigner $signer,
        private QrCodeGeneratorInterface $qrCodes,
        private Settings $settings,
    ) {}

    public function create(Permit $permit): PrintablePermit
    {
        $query = $permit->guest ? ['guest' => '1'] : [];
        $query['id'] = $permit->id;
        $query[$permit->guest ? 'apt' : 'plate'] = $permit->guest ? $permit->apartment : $permit->plate;
        $query['term'] = $permit->term;
        if (PermitSignatureVersion::Legacy !== $permit->signatureVersion) {
            $query['v'] = $permit->signatureVersion->value;
        }
        $query['sign'] = $this->signer->sign($permit);
        $url = rtrim(string: $this->settings->baseUrl, characters: '/') . '/p/check?' . http_build_query($query);

        return new PrintablePermit(permit: $permit, url: $url, image: $this->qrCodes->dataUri($url));
    }
}
