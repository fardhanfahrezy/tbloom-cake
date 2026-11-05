<?php

namespace App;

use App\Models\NotificationDeliveryStatus;
use Illuminate\Support\Facades\Http;
use Throwable;

class FonnteClient
{
    public function send(string $canonicalRecipient, string $message): NotificationSendResult
    {
        $token = config('services.fonnte.token');
        if (config('notifications.enabled') !== true
            || ! is_string($token) || ! preg_match('/\A[\x21-\x2b\x2d-\x7e]{1,1024}\z/D', $token)
            || ! preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $canonicalRecipient)
            || ! mb_check_encoding($message, 'UTF-8') || trim($message) === '' || mb_strlen($message) > 4096
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)) {
            return new NotificationSendResult(NotificationDeliveryStatus::Failed);
        }

        if (app()->environment('testing')) {
            Http::preventStrayRequests();
        }

        $target = substr($canonicalRecipient, 1);
        try {
            $response = Http::asForm()->acceptJson()->withHeaders(['Authorization' => $token])
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://api.fonnte.com/send', [
                    'target' => $target, 'message' => $message, 'countryCode' => '0',
                    'connectOnly' => 'true', 'preview' => 'false',
                ]);

            $data = $response->json();
            if (! $response->successful() || ! is_array($data)
                || (array_key_exists('status', $data) && array_key_exists('Status', $data) && $data['status'] !== $data['Status'])) {
                return new NotificationSendResult(NotificationDeliveryStatus::Unknown);
            }

            $status = $data['status'] ?? $data['Status'] ?? null;
            if ($status === true && isset($data['id']) && is_array($data['id']) && array_is_list($data['id']) && count($data['id']) === 1
                && is_string($data['id'][0]) && preg_match('/\A[\x21-\x7e]{1,128}\z/D', $data['id'][0])
                && ($data['target'] ?? null) === [$target]) {
                return new NotificationSendResult(NotificationDeliveryStatus::Sent, $data['id'][0]);
            }

            if ($status === false && ! array_key_exists('id', $data) && ! array_key_exists('target', $data)
                && is_string($data['reason'] ?? null) && trim($data['reason']) !== '') {
                $permanent = in_array($data['reason'], ['token invalid', 'devices must belong to an account', 'input invalid',
                    'target invalid', 'JSON format invalid', 'url invalid', 'file format not supported'], true);

                return new NotificationSendResult($permanent ? NotificationDeliveryStatus::Failed : NotificationDeliveryStatus::Pending);
            }
        } catch (Throwable) {
            return new NotificationSendResult(NotificationDeliveryStatus::Unknown);
        }

        return new NotificationSendResult(NotificationDeliveryStatus::Unknown);
    }
}
