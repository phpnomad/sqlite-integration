<?php

namespace PHPNomad\SQLite\Integration\Adapters;

use DateTime;
use PHPNomad\Database\Interfaces\CanConvertDatabaseStringToDateTime;
use PHPNomad\Database\Interfaces\CanConvertToDatabaseDateString;

/**
 * SQLite stores dates as TEXT in ISO 8601 ('YYYY-MM-DD HH:MM:SS' is the
 * canonical form per SQLite docs). We use the same shape as the MySQL
 * adapter so entity classes don't need a different mapper per backend.
 */
class DatabaseDateAdapter implements CanConvertToDatabaseDateString, CanConvertDatabaseStringToDateTime
{
    public function toDatabaseDateString(DateTime $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    public function toDateTime(string $date): DateTime
    {
        $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        if ($parsed === false) {
            // Tolerate ISO 8601 with T separator and timezone, which is what
            // CURRENT_TIMESTAMP-flavored values produce.
            $parsed = new DateTime($date);
        }
        return $parsed;
    }
}
