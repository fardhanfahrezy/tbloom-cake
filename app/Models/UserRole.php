<?php

namespace App\Models;

enum UserRole: string
{
    case Customer = 'customer';
    case Admin = 'admin';
    case Owner = 'owner';
}
