<?php

namespace App;

class CanonicalContact
{
    public static function email(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function whatsapp(string $number): string
    {
        $number = trim($number);
        if (! preg_match('/\A[+0-9 ()-]+\z/', $number)) {
            return $number;
        }
        $number = str_replace([' ', '(', ')', '-'], '', $number);
        if (str_starts_with($number, '08')) {
            return '+62'.substr($number, 1);
        }
        if (str_starts_with($number, '62')) {
            return '+'.$number;
        }
        if (str_starts_with($number, '00')) {
            return '+'.substr($number, 2);
        }

        return $number;
    }
}
