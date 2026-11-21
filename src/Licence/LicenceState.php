<?php

namespace Passorde\Licence;

enum LicenceState: string
{
    case Valid = 'valid';
    case Grace = 'grace';
    case Expired = 'expired';
    case Invalid = 'invalid';

    public function exitCode(): int
    {
        return match ($this) {
            self::Valid => 0,
            self::Grace => 1,
            self::Expired, self::Invalid => 2,
        };
    }
}
