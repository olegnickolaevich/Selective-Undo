<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Support;

final class Time
{
    /** MySQL UTC DATETIME → ISO 8601 with Z, or null. */
    public static function iso(mixed $mysqlUtc): ?string
    {
        if (!is_string($mysqlUtc) || $mysqlUtc === '' || str_starts_with($mysqlUtc, '0000')) {
            return null;
        }

        $ts = strtotime($mysqlUtc . ' UTC');

        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    public static function mysqlFromIso(string $iso): ?string
    {
        $ts = strtotime($iso);

        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }
}
