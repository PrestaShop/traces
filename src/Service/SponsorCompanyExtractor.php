<?php

namespace PrestaShop\Traces\Service;

/**
 * Reads the "Sponsor company" row of a pull request description.
 */
class SponsorCompanyExtractor
{
    /**
     * Value of the row, lowercased, or an empty string when the row is missing or empty.
     */
    public function extract(string $body): string
    {
        // Up to the closing pipe of the cell if any
        if (preg_match('/\|\h+Sponsor company\h+\|\h*([^|\r\n]*)/mu', $body, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return '';
    }
}
