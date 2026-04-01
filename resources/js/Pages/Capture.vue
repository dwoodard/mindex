<script setup lang="ts">
import { Head, usePage, useHttp } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { echo } from '@laravel/echo-vue';
import { Mic, MicOff, Send, Brain, Headphones } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { capture as captureRoute } from '@/routes';
import * as captureApi from '@/routes/capture';
import type { Team } from '@/types';

interface PipelinePayload {
    session_id: string;
    status: string;
    timestamp: string;
}

interface GraphUpdatedPayload {
    session_id: string;
    nodes_added: number;
    edges_added: number;
    timestamp: string;
}

const page = usePage();
const userId = computed(() => (page.props.auth as { user: { id: number } })?.user?.id);
const currentTeam = computed(() => page.props.currentTeam as Team | null);

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Capture',
                href: props.currentTeam ? captureRoute(props.currentTeam.slug).url : '/capture',
            },
        ],
    }),
});

// Mode & input
const listenMode = ref(true);
const textInput = ref('');

// Pipeline state
const sessionId = ref<string | null>(null);
const pipelineStatus = ref<string | null>(null);
const lastGraphUpdate = ref<{ nodesAdded: number; edgesAdded: number } | null>(null);

// Audio recording
const isRecording = ref(false);
const micPermissionDenied = ref(false);
let mediaRecorder: MediaRecorder | null = null;
let audioChunks: Blob[] = [];

// Text submission via useHttp
const textHttp = useHttp({ input: '', listen_mode: true });

// --- Echo subscriptions ---

function subscribeToSession(id: string) {
    echo()
        .private(`capture.${id}`)
        .listen('.PipelineStatusEvent', (payload: PipelinePayload) => {
            pipelineStatus.value = payload.status;
        });
}

function leaveSession(id: string) {
    echo().leave(`capture.${id}`);
}

watch(sessionId, (newId, oldId) => {
    if (oldId) leaveSession(oldId);
    if (newId) subscribeToSession(newId);
});

onMounted(() => {
    if (!userId.value) return;
    echo()
        .private(`graph.${userId.value}`)
        .listen('.GraphUpdatedEvent', (payload: GraphUpdatedPayload) => {
            lastGraphUpdate.value = {
                nodesAdded: payload.nodes_added,
                edgesAdded: payload.edges_added,
            };
        });
});

onUnmounted(() => {
    if (sessionId.value) leaveSession(sessionId.value);
    if (userId.value) echo().leave(`graph.${userId.value}`);
});

// --- Submit text ---

function submitText() {
    const trimmed = textInput.value.trim();
    if (!trimmed || textHttp.processing) return;

    pipelineStatus.value = null;
    lastGraphUpdate.value = null;
    textHttp.input = trimmed;
    textHttp.listen_mode = listenMode.value;

    textHttp.post(captureApi.text.url(), {
        onSuccess: (response: unknown) => {
            const data = response as { session_id: string };
            sessionId.value = data.session_id;
            textInput.value = '';
        },
        onError: () => {
            pipelineStatus.value = 'failed';
        },
    });
}

function handleTextKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        submitText();
    }
}

// --- Audio recording ---

async function startRecording() {
    micPermissionDenied.value = false;
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        audioChunks = [];
        mediaRecorder = new MediaRecorder(stream);

        mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) audioChunks.push(e.data);
        };

        mediaRecorder.onstop = () => {
            stream.getTracks().forEach((track) => track.stop());
            const blob = new Blob(audioChunks, { type: 'audio/webm' });
            submitAudio(blob);
        };

        mediaRecorder.start();
        isRecording.value = true;
    } catch {
        micPermissionDenied.value = true;
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    isRecording.value = false;
}

async function submitAudio(blob: Blob) {
    pipelineStatus.value = 'transcribing';
    lastGraphUpdate.value = null;

    const formData = new FormData();
    formData.append('audio', blob, 'capture.webm');
    formData.append('listen_mode', listenMode.value ? '1' : '0');

    const tokenMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const csrfToken = tokenMatch ? decodeURIComponent(tokenMatch[1]) : '';

    try {
        const response = await fetch(captureApi.audio.url(), {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': csrfToken },
            credentials: 'same-origin',
            body: formData,
        });

        if (!response.ok) throw new Error('Upload failed');

        const data: { session_id: string } = await response.json();
        sessionId.value = data.session_id;
    } catch {
        pipelineStatus.value = 'failed';
    }
}

// --- Derived UI state ---

const statusLabel: Record<string, string> = {
    queued: 'Queued...',
    transcribing: 'Transcribing audio...',
    extracting: 'Extracting meaning...',
    validating: 'Validating...',
    writing: 'Writing to graph...',
    done: 'Done',
    failed: 'Failed',
};

const statusSteps = ['transcribing', 'extracting', 'validating', 'writing', 'done'];

function stepIndex(status: string): number {
    return statusSteps.indexOf(status);
}

const isProcessing = computed(
    () =>
        textHttp.processing ||
        (pipelineStatus.value !== null &&
            pipelineStatus.value !== 'done' &&
            pipelineStatus.value !== 'failed'),
);
</script>

<template>
    <Head title="Capture" />

    <div class="flex h-full flex-1 flex-col items-center justify-center gap-8 p-6">
        <!-- Mode toggle -->
        <div class="flex items-center gap-1 rounded-full border border-border bg-muted p-1">
            <button
                class="flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium transition-colors"
                :class="
                    listenMode
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="listenMode = true"
            >
                <Headphones class="size-4" />
                Listen
            </button>
            <button
                class="flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium transition-colors"
                :class="
                    !listenMode
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="listenMode = false"
            >
                <Brain class="size-4" />
                Interact
            </button>
        </div>

        <!-- Input area -->
        <div class="w-full max-w-xl space-y-4">
            <div class="relative">
                <textarea
                    v-model="textInput"
                    placeholder="Type something... or hold the mic to speak."
                    rows="4"
                    :disabled="isProcessing"
                    class="w-full resize-none rounded-xl border border-input bg-background px-4 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50"
                    @keydown="handleTextKeydown"
                />
                <span class="absolute bottom-3 right-3 text-xs text-muted-foreground">
                    ⌘↵ to send
                </span>
            </div>

            <div class="flex items-center gap-3">
                <!-- Submit text -->
                <Button
                    variant="default"
                    class="flex-1"
                    :disabled="!textInput.trim() || isProcessing"
                    @click="submitText"
                >
                    <Send class="mr-2 size-4" />
                    Send
                </Button>

                <!-- Hold to record -->
                <button
                    class="relative flex size-12 shrink-0 items-center justify-center rounded-full border-2 transition-all select-none"
                    :class="
                        isRecording
                            ? 'border-red-500 bg-red-500 text-white shadow-lg shadow-red-500/30 scale-110'
                            : 'border-border bg-background text-foreground hover:border-primary hover:text-primary'
                    "
                    :disabled="isProcessing && !isRecording"
                    @mousedown.prevent="startRecording"
                    @mouseup="stopRecording"
                    @mouseleave="isRecording && stopRecording()"
                    @touchstart.prevent="startRecording"
                    @touchend="stopRecording"
                >
                    <MicOff v-if="micPermissionDenied" class="size-5" />
                    <Mic v-else class="size-5" />

                    <!-- Pulse ring when recording -->
                    <span
                        v-if="isRecording"
                        class="absolute inset-0 animate-ping rounded-full bg-red-500 opacity-30"
                    />
                </button>
            </div>

            <p v-if="micPermissionDenied" class="text-center text-xs text-destructive">
                Microphone access denied. Check your browser permissions.
            </p>
        </div>

        <!-- Pipeline status -->
        <div
            v-if="pipelineStatus"
            class="w-full max-w-xl space-y-3 rounded-xl border border-border bg-muted/50 p-4"
        >
            <!-- Steps indicator -->
            <div class="flex items-center gap-1.5">
                <template v-for="(step, i) in statusSteps" :key="step">
                    <div
                        class="h-1.5 flex-1 rounded-full transition-colors duration-500"
                        :class="
                            pipelineStatus === 'failed' && stepIndex(pipelineStatus) === -1
                                ? 'bg-destructive'
                                : stepIndex(pipelineStatus ?? '') >= i
                                  ? step === 'done'
                                    ? 'bg-green-500'
                                    : 'bg-primary'
                                  : 'bg-border'
                        "
                    />
                </template>
            </div>

            <!-- Status text -->
            <div class="flex items-center justify-between">
                <span
                    class="text-sm"
                    :class="
                        pipelineStatus === 'failed'
                            ? 'text-destructive'
                            : pipelineStatus === 'done'
                              ? 'text-green-600 dark:text-green-400'
                              : 'text-muted-foreground'
                    "
                >
                    {{ statusLabel[pipelineStatus] ?? pipelineStatus }}
                </span>

                <span
                    v-if="pipelineStatus !== 'done' && pipelineStatus !== 'failed'"
                    class="size-3 animate-spin rounded-full border-2 border-primary border-t-transparent"
                />
            </div>
        </div>

        <!-- Graph update toast -->
        <transition
            enter-active-class="transition-all duration-500 ease-out"
            enter-from-class="opacity-0 translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-300 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-2"
        >
            <div
                v-if="lastGraphUpdate && pipelineStatus === 'done'"
                class="flex items-center gap-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-300"
            >
                <span class="text-base">✦</span>
                <span>
                    <span class="font-semibold">+{{ lastGraphUpdate.nodesAdded }}</span>
                    {{ lastGraphUpdate.nodesAdded === 1 ? 'node' : 'nodes' }}
                    &nbsp;&middot;&nbsp;
                    <span class="font-semibold">+{{ lastGraphUpdate.edgesAdded }}</span>
                    {{ lastGraphUpdate.edgesAdded === 1 ? 'edge' : 'edges' }}
                    added to graph
                </span>
            </div>
        </transition>
    </div>
</template>
