<?php

namespace App\Models;

enum ProductCategory: string
{
    case Simple = 'simple';
    case Cupcake = 'cupcake';
    case Bento = 'bento';
}
