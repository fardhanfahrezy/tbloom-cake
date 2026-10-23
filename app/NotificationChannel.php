<?php

namespace App;

enum NotificationChannel: string
{
    case WhatsApp = 'whatsapp';
    case Email = 'email';
}
