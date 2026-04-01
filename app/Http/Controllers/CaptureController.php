<?php

namespace App\Http\Controllers;

use App\Http\Requests\AudioCaptureRequest;
use App\Http\Requests\TextCaptureRequest;
use App\Jobs\ProcessCaptureJob;
use App\Jobs\TranscribeAudioJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CaptureController extends Controller
{
    public function text(TextCaptureRequest $request): JsonResponse
    {
        $sessionId = (string) Str::uuid();

        ProcessCaptureJob::dispatch(
            input: $request->validated('input'),
            userId: $request->user()->id,
            sessionId: $sessionId,
            listenMode: $request->boolean('listen_mode', true),
        );

        return response()->json(['session_id' => $sessionId], 202);
    }

    public function audio(AudioCaptureRequest $request): JsonResponse
    {
        $sessionId = (string) Str::uuid();

        $path = $request->file('audio')->store('captures/audio', 'local');

        TranscribeAudioJob::dispatch(
            audioPath: $path,
            userId: $request->user()->id,
            sessionId: $sessionId,
            listenMode: $request->boolean('listen_mode', true),
        );

        return response()->json(['session_id' => $sessionId], 202);
    }
}
