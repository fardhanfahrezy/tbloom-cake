<?php

namespace App\Models;

enum TestimonialPublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
