<?php

namespace App\Enums;

enum PointStatus: string
{
    case ATester = 'a_tester';
    case Valide = 'valide';
    case AEviter = 'a_eviter';
    case DejaDormi = 'deja_dormi';
}
