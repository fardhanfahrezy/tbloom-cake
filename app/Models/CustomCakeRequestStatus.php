<?php

namespace App\Models;

enum CustomCakeRequestStatus: string
{
    case Submitted = 'submitted';
    case Contacted = 'contacted';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
