<?php

namespace App\Service;

use Com\Tecnick\Barcode\Barcode;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Generación de PDFs de resultados con dompdf + código de barras Code128.
 * Replica la salida de lab-results-api (result-test.hbs + JsBarcode).
 */
class PdfService
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * Genera el PDF a partir del DTO de findByRequest().
     */
    public function render(array $detail): string
    {
        $barcodeImg = $this->barcodeBase64((string) ($detail['barcode'] ?? ''));

        $html = $this->twig->render('pdf/result.html.twig', [
            'detail' => $detail,
            'barcode_img' => $barcodeImg,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'verdana');
        $options->set('dpi', 96);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    /**
     * Código de barras Code128 como data URI PNG.
     */
    private function barcodeBase64(string $code): string
    {
        if ($code === '') {
            return '';
        }
        try {
            $barcode = new Barcode();
            $bobj = $barcode->getBarcodeObj('C128', $code, 520, 60, 'black', [0, 0, 0, 0]);
            $png = $bobj->getPngData();
            return 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
