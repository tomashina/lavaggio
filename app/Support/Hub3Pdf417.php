<?php

namespace App\Support;

use Com\Tecnick\Barcode\Type\Square\PdfFourOneSeven;

/** PDF417 parameters required by the Croatian HUB3A EUR specification. */
class Hub3Pdf417 extends PdfFourOneSeven
{
    protected function setParameters(): void
    {
        $this->row_height = 3;
        $this->ecl = 4;
        // The library derives columns from aspect ratio. Solve its equation for exactly 9.
        $codewordCount = count($this->getCompaction(901, $this->code, true)) + 33;
        $this->aspectratio = (375 ** 2 - 4761) / (68 * 3 * $codewordCount);
    }

    protected function getInputSequences(string $code): array
    {
        return [[901, $code]];
    }
}
