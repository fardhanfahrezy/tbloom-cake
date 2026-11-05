<?php

namespace App\Http\Controllers;

use App\CustomRequestAttachments;
use App\Models\CustomCakeRequestAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomRequestAttachmentController extends Controller
{
    public function show(Request $request, CustomCakeRequestAttachment $attachment, CustomRequestAttachments $files): Response
    {
        $file = $files->download($attachment->id, $request->user());

        return new Response($file['bytes'], 200, ['Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
