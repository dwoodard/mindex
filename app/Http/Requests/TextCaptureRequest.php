<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TextCaptureRequest extends FormRequest
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
            'input' => ['required', 'string', 'min:1', 'max:10000'],
            'listen_mode' => ['boolean'],
        ];
    }
}
