<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AudioCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'audio' => ['required', 'file', 'mimes:webm,ogg,mp4,m4a,wav', 'max:25600'],
            'listen_mode' => ['boolean'],
        ];
    }
}
