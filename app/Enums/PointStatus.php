<?php

namespace App\Enums;

enum PointStatus: string
{
    case ToTest = 'to_test';
    case Validated = 'validated';
    case ToAvoid = 'to_avoid';
    case AlreadySlept = 'already_slept';
}
