<?php

namespace App\Services\Shipping;

use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;

class PackageLabelPdf
{
    public function render(string $view, array $data): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [resource_path('views/admin/orders')]);

        foreach ([10, 9, 8] as $fontSize) {
            $pdf = new Dompdf($options);
            $pdf->setPaper([0, 0, 100 * 72 / 25.4, 150 * 72 / 25.4]);
            $pdf->loadHtml(view($view, $data + compact('fontSize'))->render(), 'UTF-8');
            $pdf->render();
            if ($pdf->getCanvas()->get_page_count() === 1) {
                return $pdf->output();
            }
        }

        throw new InvalidArgumentException('Podaci su predugi za naljepnicu 10 × 15 cm. Provjerite duljinu naziva i adresa prije ispisa.');
    }
}
