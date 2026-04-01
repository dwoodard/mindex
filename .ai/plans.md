# Mindex — Project Kickoff Prompt

Use this document as the master context prompt when starting development.
Paste into Cursor, Claude Code, or hand to a developer as the source of truth.

---

## ✅ Done

### Infrastructure

- [x] Neo4j running in Docker (`docker-compose.yml`) — port 7474 (browser) + 7687 (bolt), password `neo4jtest`
- [x] Laravel Reverb running in Docker — port 8080, custom PHP 8.4 image with `pcntl` extension
- [x] Demo seed data loaded into Neo4j (`database/neo4j/demo.cypher`)

### Packages Installed

- [x] `laravel/ai` — first-party Laravel AI SDK
- [x] `laudis/neo4j-php-client` v3.4 — Neo4j Bolt driver
- [x] `laravel/horizon` v5.45 — queue dashboard, published + configured

### Schema Foundation

- [x] `app/Enums/NodeType.php` — 10 node types (Person, Idea, Project, Belief, Question, Preference, Dislike, Event, Place, Resource)
- [x] `app/Enums/RelationType.php` — 15 relationship types
- [x] `app/Enums/WriteIntent.php` — 6 write intents (Create, Reinforce, Update, Evolve, Contradict, Resolve)
- [x] `app/Enums/Origin.php` — 4 origins (User, Claude, Inferred, System)
- [x] `app/DTOs/GraphNode.php` — persisted node (readonly)
- [x] `app/DTOs/GraphEdge.php` — persisted edge (readonly)
- [x] `app/DTOs/GraphNodeDraft.php` — AI output node before validation (readonly)
- [x] `app/DTOs/GraphEdgeDraft.php` — AI output edge before validation (readonly)
- [x] `app/DTOs/IntentDeclaration.php` — per-node intent declaration from AI (readonly)
- [x] `app/DTOs/WritePayload.php` — full AI response envelope (readonly)
- [x] `app/DTOs/ValidatedPayload.php` — output of the validator, passed to the write step

### Services & Events

- [x] `app/Services/Contracts/GraphServiceInterface.php` — contract for graph reads/writes
- [x] `app/Services/GraphServiceStub.php` — JSON file store implementation for early development
- [x] `app/Services/ExtractionService.php` — `laravel/ai` structured output call, returns `WritePayload`
- [x] `app/Services/IntentValidatorService.php` — validates/overrides AI intent against existing graph state
- [x] `app/Events/PipelineStatusEvent.php` — broadcasts transcribing | extracting | done status
- [x] `app/Events/GraphUpdatedEvent.php` — broadcasts nodes/edges added this turn
- [x] `app/Events/ConflictDetectedEvent.php` — broadcasts when CONTRADICT intent is detected

---

## 🔨 In Progress

Being built right now by parallel agents:

1. **`ProcessCaptureJob`** (`app/Jobs/ProcessCaptureJob.php`)
   Wires the full pipeline together. Steps in order:
   - Normalise input to UTF-8 string
   - Retrieve related nodes from graph (via `GraphServiceInterface`)
   - Build extraction prompt with retrieved context
   - Call `ExtractionService` → get `WritePayload`
   - Call `IntentValidatorService` → get `ValidatedPayload`
   - Broadcast `PipelineStatusEvent` at each stage
   - Write nodes/edges via `GraphServiceInterface`
   - Broadcast `GraphUpdatedEvent` with counts
   - If contradictions → broadcast `ConflictDetectedEvent`
   - Return reply (empty in listen mode)
   Uses `#[Tries(3)]` `#[Backoff(60)]` Laravel 13 job attributes.

2. **`CaptureController`** (`app/Http/Controllers/CaptureController.php`) + **`TextCaptureRequest`** + **`AudioCaptureRequest`** + routes
   POST `/api/capture/text` and `/api/capture/audio`. Dispatches `ProcessCaptureJob`.
   Returns `session_id` so the frontend can subscribe to the correct Reverb channel.

3. **`TranscribeAudioJob`** (`app/Jobs/TranscribeAudioJob.php`)
   Receives audio file path, calls OpenAI Whisper API. On success dispatches
   `ProcessCaptureJob` with the transcript. On failure broadcasts `PipelineStatusEvent`
   with `'failed'` status.

4. **`config/mindex.php`** + **`AppServiceProvider`** service binding
   Config file for Neo4j connection settings, Whisper settings, and decay defaults.
   Register in `AppServiceProvider` binding `GraphServiceInterface` to `GraphServiceStub`
   (swap to real `GraphService` once Neo4j is proven stable).

5. **`GraphService`** (real Neo4j) (`app/Services/GraphService.php`)
   Swap `GraphServiceStub` for full `laudis/neo4j-php-client` implementation.
   Same `GraphServiceInterface`, different backend — no pipeline changes needed.

---

## 🔜 Up Next

After the current batch lands, build in this order — each step unblocks the next:

1. **`Capture.vue`** (`resources/js/Pages/Capture.vue`)
   Main PWA screen. Hold-to-record audio button, text input, mode toggle (Listen/Interact).
   Wire Reverb listeners from day one for pipeline status and graph updates — do not ship
   this screen without real-time feedback already connected.

2. **PWA config**
   `manifest.json`, service worker (cache-first static, network-first API), offline capture
   queue using IndexedDB + Background Sync. Do not build until the core pipeline is stable.

3. **Auth hardening**
   All Reverb channels are private and require authenticated sessions.
   Sanctum API tokens for mobile clients. Add this before any deployment.

4. **`DecayConfidenceJob`** (`app/Jobs/DecayConfidenceJob.php`)
   Nightly cron at 2am via Laravel Scheduler. For every non-anchored node not reinforced
   in 7 days: `confidence -= decay_rate`, minimum `0.05`. Nodes below `0.2` flagged as
   `'faded'` and excluded from retrieval context by default.

---

## What We Are Building

A **Progressive Web App** (PWA) that acts as a personal intelligence layer.
The user talks or types freely. The system listens, extracts meaning, and builds
a living knowledge graph in Neo4j — silently, in the background.

Over time the AI becomes deeply contextual about the user's world: their people,
projects, beliefs, ideas, preferences, and how all of those things relate and
change over time.

There are two modes:
- **Listen Mode** — user captures freely. AI says nothing. Graph builds silently.
- **Interact Mode** — user asks questions. AI draws on the full graph to respond.
  It is never starting from zero.

This is not a chatbot. It is not a note-taking app.
It is a second mind that grows alongside the user.

---

## Core Principles

1. **Zero friction capture** — one tap to record, paste, or type. No forms, no tags, no categories. The AI handles all structure.
2. **Provenance is first class** — every node and edge knows who originated it: the user, the AI, or inference. These are never mixed up.
3. **History is never deleted** — ideas evolve, beliefs change. The graph extends with `EVOLVED_INTO` and `CONTRADICTED_BY` edges. Old versions stay.
4. **Retrieve before write** — the pipeline always queries existing graph context before making any AI extraction call. The AI sees what already exists.
5. **Prism owns the shape, AI owns the meaning, Laravel owns the write decision** — the AI cannot bypass schema validation or silently overwrite history.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13 (PHP 8.3+) |
| AI Structured Output | Prism PHP (`echolabs/prism`) |
| Graph Database | Neo4j (Bolt protocol) |
| Frontend | Inertia.js + Vue 3 |
| PWA | Vite PWA Plugin (`vite-plugin-pwa`) |
| Audio Transcription | OpenAI Whisper API |
| Auth | Laravel Sanctum (API tokens + session) |
| WebSockets | Laravel Reverb |
| Scheduling | Laravel Scheduler (nightly decay cron) |

---

## Project Structure

```
mindex/
├── app/
│   ├── Enums/
│   │   ├── NodeType.php
│   │   ├── RelationType.php
│   │   ├── WriteIntent.php
│   │   └── Origin.php
│   ├── DTOs/
│   │   ├── GraphNode.php
│   │   ├── GraphEdge.php
│   │   ├── GraphEdgeDraft.php
│   │   ├── GraphNodeDraft.php
│   │   ├── IntentDeclaration.php
│   │   └── WritePayload.php
│   ├── Jobs/
│   │   ├── ProcessCaptureJob.php
│   │   ├── TranscribeAudioJob.php
│   │   └── DecayConfidenceJob.php
│   ├── Services/
│   │   ├── Contracts/
│   │   │   └── GraphServiceInterface.php  ← define this first, stub before Neo4j
│   │   ├── GraphService.php               ← real Neo4j impl, swap in at step 11
│   │   ├── GraphServiceStub.php           ← JSON file store for early development
│   │   ├── ExtractionService.php
│   │   └── IntentValidatorService.php
│   ├── Http/Controllers/
│   │   ├── CaptureController.php
│   │   └── GraphController.php
│   └── Prism/
│       └── GraphExtractionSchema.php
├── resources/
│   ├── js/
│   │   ├── app.js
│   │   ├── Pages/
│   │   │   ├── Capture.vue          ← main screen
│   │   │   ├── Interact.vue         ← ask the graph anything
│   │   │   └── Graph.vue            ← optional graph visualizer
│   │   └── Components/
│   │       ├── AudioRecorder.vue
│   │       ├── TextCapture.vue
│   │       ├── ModeToggle.vue
│   │       └── CaptureQueue.vue
│   └── views/
│       └── app.blade.php
├── public/
│   ├── manifest.json
│   ├── sw.js                        ← service worker
│   └── icons/                       ← PWA icons all sizes
├── routes/
│   ├── web.php
│   └── api.php
└── config/
    └── Mindex.php                ← neo4j, whisper, decay settings
```

---

## Enums (Prism enforces these — AI cannot invent outside them)

### NodeType

```php
enum NodeType: string {
    case Person     = 'Person';
    case Idea       = 'Idea';
    case Project    = 'Project';
    case Belief     = 'Belief';
    case Question   = 'Question';
    case Preference = 'Preference';
    case Dislike    = 'Dislike';
    case Event      = 'Event';
    case Place      = 'Place';
    case Resource   = 'Resource';
}
```

### RelationType

```php
enum RelationType: string {
    case ORIGINATED       = 'ORIGINATED';
    case SUGGESTED        = 'SUGGESTED';
    case REJECTED         = 'REJECTED';
    case EVOLVED_INTO     = 'EVOLVED_INTO';
    case CONTRADICTED_BY  = 'CONTRADICTED_BY';
    case REINFORCES       = 'REINFORCES';
    case RELATES_TO       = 'RELATES_TO';
    case BLOCKS           = 'BLOCKS';
    case ENABLES          = 'ENABLES';
    case HAS_QUESTION     = 'HAS_QUESTION';
    case PREFERS          = 'PREFERS';
    case HAS_AVERSION_TO  = 'HAS_AVERSION_TO';
    case WORKS_WITH       = 'WORKS_WITH';
    case BUILT_ON         = 'BUILT_ON';
    case MENTIONS         = 'MENTIONS';
}
```

### WriteIntent

```php
enum WriteIntent: string {
    case CREATE     = 'CREATE';
    case REINFORCE  = 'REINFORCE';
    case UPDATE     = 'UPDATE';
    case EVOLVE     = 'EVOLVE';
    case CONTRADICT = 'CONTRADICT';
    case RESOLVE    = 'RESOLVE';
}
```

### Origin

```php
enum Origin: string {
    case USER     = 'user';
    case CLAUDE   = 'claude';
    case INFERRED = 'inferred';
    case SYSTEM   = 'system';
}
```

---

## Core DTOs

### GraphNode

```php
class GraphNode
{
    public string   $id;               // snake_case, stable — same concept = same id always
    public string   $label;            // human readable, AI decides
    public NodeType $type;             // enum, Prism locked
    public Origin   $origin;           // who created this
    public float    $confidence;       // 0.0–1.0
    public Carbon   $created_at;       // system stamped
    public Carbon   $updated_at;
    public int      $mention_count;    // increments on REINFORCE
    public array    $properties;       // freeform, AI populates
    public float    $decay_rate;       // default 0.02 per week
    public ?Carbon  $last_reinforced_at;
    public bool     $anchored;         // true = never decays (user pins this)
}
```

### GraphEdge

```php
class GraphEdge
{
    public string       $id;
    public string       $source_id;
    public string       $target_id;
    public RelationType $type;         // enum, Prism locked
    public Origin       $origin;
    public float        $strength;     // 0.0–1.0
    public Carbon       $created_at;
    public ?string      $reason;       // AI explains why
    public ?string      $session_id;
    public ?Carbon      $valid_until;  // set when EVOLVED_INTO fires
}
```

### WritePayload (what Prism returns per turn)

```php
class WritePayload
{
    public string  $reply;             // conversational response (empty in listen mode)
    public array   $nodes;             // GraphNodeDraft[] — 2 to 5 per turn
    public array   $edges;             // GraphEdgeDraft[]
    public array   $intents;           // IntentDeclaration[] — one per node
    public ?string $mood;              // positive / neutral / negative
    public ?array  $open_questions;    // new Question nodes surfaced this turn
}
```

---

## The Pipeline (ProcessCaptureJob)

Build this job in this exact sequence. Do not skip or reorder steps.

```
Step 1 — Receive input
         Text string, or audio file path from TranscribeAudioJob
         Normalise everything to a plain UTF-8 string before proceeding

Step 2 — Transcribe if audio
         POST to OpenAI Whisper API
         Return plain transcript string
         Queue as separate job: TranscribeAudioJob dispatches ProcessCaptureJob on completion

Step 3 — Retrieve related nodes  ← CRITICAL
         Before any AI call, query Neo4j for nodes that may relate to this input
         Use keyword/semantic search on node labels and properties
         Pass retrieved nodes as context to the AI prompt
         This is what prevents duplicates and enables EVOLVE/CONTRADICT detection

Step 4 — Build Prism prompt
         System prompt includes:
           - Full enum vocabularies (NodeType, RelationType, WriteIntent, Origin)
           - Retrieved related nodes as JSON context
           - WritePayload schema definition
           - Instruction: "Retrieve before write — you have been given existing context.
             Declare your intent honestly. Never invent node types outside the enum."
         User message: the raw transcript/text

Step 5 — Prism extraction call
         Model: claude-sonnet (latest)
         Structured output: WritePayload
         Prism validates every field against schema
         Missing required fields = job fails, retries up to 3 times

Step 6 — Laravel intent validation  ← CRITICAL
         For each IntentDeclaration in the payload:
           - If intent = CREATE but node id already exists → override to REINFORCE
           - If intent = EVOLVE but no replaces_id provided → reject, log warning
           - If intent = CONTRADICT → flag node, do not auto-write, queue for review
         Laravel is the last line of defence. It never blindly trusts AI intent.

Step 7 — Write to Neo4j via GraphService
         MERGE nodes (never INSERT — always upsert by id)
         Write edges with session_id and created_at
         If EVOLVE: set valid_until on old edge, create new node, link with EVOLVED_INTO
         Increment mention_count on REINFORCE
         Update last_reinforced_at

Step 8 — Return response
         Listen mode: return nothing to UI, fire-and-forget
         Interact mode: return $payload->reply to UI
```

---

## Nightly Decay Cron (DecayConfidenceJob)

Run via Laravel Scheduler every night at 2am.

```php
// For every non-anchored node not reinforced in the last 7 days:
$node->confidence -= $node->decay_rate;
$node->confidence  = max(0.05, $node->confidence); // never goes to zero
$node->save();

// Nodes below 0.2 confidence get flagged as 'faded'
// Faded nodes are excluded from retrieval context by default
// User can still query them explicitly
```

---

## PWA Requirements

The app must be installable on iOS and Android as a PWA.

### manifest.json

```json
{
  "name": "Mindex",
  "short_name": "Mindex",
  "description": "Your thinking, remembered.",
  "start_url": "/",
  "display": "standalone",
  "background_color": "#080f1a",
  "theme_color": "#080f1a",
  "orientation": "portrait",
  "icons": [
    { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png" },
    { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
  ]
}
```

### Service Worker (sw.js)

- Cache-first for all static assets
- Network-first for all API calls
- Queue captures made offline and sync when connection restores
- Show offline indicator in UI when network is unavailable

---

## Capture Screen (Capture.vue) — The Main Screen

This is the screen the user sees 90% of the time.
Design it to feel calm, minimal, and always ready.

### Layout

- Full screen dark background (#080f1a)
- Large central area — either the audio waveform when recording, or a text input
- Bottom bar with three actions:
  - Hold-to-record audio button (large, centre)
  - Paste / type text button (left)
  - Mode toggle: Listen / Interact (right)
- Top bar: minimal — just the app name and a subtle indicator that graph is active

### Audio Recording (AudioRecorder.vue)

```
- Hold button to record (release to stop)
- Show live waveform while recording
- On release: POST audio blob to /api/capture/audio
- Show "processing..." state while pipeline runs
- In listen mode: no response shown, just a subtle confirmation pulse
- In interact mode: show AI response when pipeline completes
```

### Text / Paste (TextCapture.vue)

```
- Tap to open textarea
- User pastes or types freely
- Submit on Enter (with Shift+Enter for newline)
- POST text to /api/capture/text
- Same listen/interact behaviour as audio
```

### Mode Toggle (ModeToggle.vue)

```
- Two states: LISTEN and INTERACT
- LISTEN: AI is silent, graph builds, no responses shown
- INTERACT: AI responds, draws on graph context
- Persist mode in localStorage
- Show current mode clearly but unobtrusively
```

---

## API Routes

```php
// Capture
Route::post('/api/capture/text',  [CaptureController::class, 'text']);
Route::post('/api/capture/audio', [CaptureController::class, 'audio']);

// Graph queries (for Interact mode and Graph view)
Route::get('/api/graph/nodes',           [GraphController::class, 'nodes']);
Route::get('/api/graph/node/{id}',       [GraphController::class, 'node']);
Route::get('/api/graph/traverse/{id}',   [GraphController::class, 'traverse']);
Route::post('/api/graph/query',          [GraphController::class, 'naturalLanguage']);
Route::patch('/api/graph/node/{id}/anchor', [GraphController::class, 'anchor']);
```

---

## Prism System Prompt (ExtractionService.php)

Use this as the base system prompt for every extraction call.

```
You are a personal intelligence extraction engine embedded in a knowledge graph system.

Your job is to read what the user said and extract structured graph data from it.

You have been given existing graph context (nodes already in the graph that may relate
to this input). Use this context to:
- Avoid creating duplicate nodes
- Detect when beliefs or ideas have evolved or changed
- Detect contradictions with existing nodes
- Reinforce existing nodes when the user returns to a topic

RULES:
1. Extract 2–5 nodes per turn. Do not over-extract. Quality over quantity.
2. Node ids must be stable snake_case. Same concept = same id every time.
3. Node types must come from the NodeType enum only. Never invent new types.
4. Relationship types must come from the RelationType enum only.
5. Declare your WriteIntent for each node honestly.
6. Origin must be accurate: 'user' if they said it, 'inferred' if you derived it.
7. If this is listen mode, leave reply empty.
8. If you detect a contradiction with an existing node, set intent to CONTRADICT
   and explain in the reason field. Do not silently overwrite.
9. Confidence should reflect how certain you are this extraction is accurate.
   Be honest. A passing mention warrants 0.3. A strong clear statement warrants 0.8+.
10. Properties are freeform but keep them brief — 1 to 3 key facts only.

EXISTING GRAPH CONTEXT:
{retrieved_nodes_json}

Respond only with a valid WritePayload JSON object. No markdown. No explanation.
```

---

## Laravel Reverb (WebSockets)

Reverb provides real-time feedback between the pipeline and the PWA.
Without it the UI is blind — captures go in, nothing comes back until a full
page reload. With it the app feels alive.

### Where Reverb is used in this project

**Pipeline status feedback**
Audio capture queues a job that runs Whisper then Prism. That takes 3–5 seconds.
Broadcast status events back to the UI during that window:

```php
// Inside ProcessCaptureJob — broadcast at each stage
broadcast(new PipelineStatusEvent($sessionId, 'transcribing'));
broadcast(new PipelineStatusEvent($sessionId, 'extracting'));
broadcast(new PipelineStatusEvent($sessionId, 'done'));
```

**Live graph updates**
When GraphService writes new nodes/edges to Neo4j, broadcast the diff.
The PWA receives it and updates the node count or pulses the indicator —
the user sees something was captured without any polling.

```php
// Inside GraphService::writeNodes()
broadcast(new GraphUpdatedEvent($userId, [
    'nodes_added'  => count($newNodes),
    'edges_added'  => count($newEdges),
    'session_id'   => $sessionId,
]));
```

**Interact mode streaming**
Stream the AI response token by token as it arrives from the Prism call,
rather than waiting for the full response before showing anything.

**Contradiction alerts**
When IntentValidatorService detects a `CONTRADICT` intent, push a real-time
notification: *"Something you just said conflicts with a belief from 6 months ago."*

```php
broadcast(new ConflictDetectedEvent($userId, $existingNode, $newNode));
```

### Laravel 13 + Laradock Setup

Laravel 13 ships with a native database driver for Reverb. During local dev
you can run Reverb through the database connection — no separate process needed
until production.

```bash
# Install Reverb (inside Laradock workspace container)
php artisan install:broadcasting

# Start Reverb server
php artisan reverb:start

# Or add to your daily dev workflow alongside Horizon
php artisan reverb:start --debug
```

### Laradock .env — enable Reverb container

```env
# Enable the Reverb container in Laradock for production-like local testing
LARAVEL_REVERB=true
LARAVEL_REVERB_PORT=8080
```

### Laravel .env — Reverb config

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=mindex
REVERB_APP_KEY=mindex-key
REVERB_APP_SECRET=mindex-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

# Frontend uses these to connect
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Frontend (Vue) — listening for events

```js
// In Capture.vue — listen for pipeline status and graph updates
import { useEcho } from '@laravel/echo-vue'

const echo = useEcho()

// Pipeline status during capture
echo.private(`capture.${sessionId}`)
    .listen('PipelineStatusEvent', (e) => {
        captureStatus.value = e.status // 'transcribing' | 'extracting' | 'done'
    })

// Live graph node count
echo.private(`graph.${userId}`)
    .listen('GraphUpdatedEvent', (e) => {
        nodeCount.value += e.nodes_added
        edgeCount.value += e.edges_added
        triggerPulse() // subtle UI indicator
    })

// Contradiction alerts (interact mode)
echo.private(`graph.${userId}`)
    .listen('ConflictDetectedEvent', (e) => {
        showConflictAlert(e.existingNode, e.newNode)
    })
```

### Events to Create

```
app/Events/
├── PipelineStatusEvent.php    # transcribing | extracting | done
├── GraphUpdatedEvent.php      # nodes/edges added this turn
└── ConflictDetectedEvent.php  # CONTRADICT intent detected
```

All three should implement `ShouldBroadcast` and broadcast on private channels
authenticated by the user's session.

### Daily Dev Workflow (updated)

```bash
cd laradock && docker-compose up -d workspace nginx redis neo4j

docker-compose exec workspace bash

# Three processes — run each in a separate terminal tab
php artisan horizon          # queue worker
php artisan reverb:start     # websocket server
npm run dev                  # vite dev server
```

---

## Environment Variables (.env)

```env
# Neo4j
NEO4J_HOST=neo4j
NEO4J_PORT=7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=test
NEO4J_DATABASE=neo4j

# AI
ANTHROPIC_API_KEY=
OPENAI_API_KEY=           # for Whisper transcription

# Queue
QUEUE_CONNECTION=redis
REDIS_HOST=redis

# Broadcasting
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=mindex
REVERB_APP_KEY=mindex-key
REVERB_APP_SECRET=mindex-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# App
APP_NAME=Mindex
APP_URL=http://localhost
APP_MODE=listen            # default mode: listen or interact
```

---

## Laravel 13 Notes

Laravel 13 was released March 17, 2026. Key things relevant to this project:

- **PHP 8.3 minimum** — set `PHP_VERSION=8.3` in Laradock `.env`
- **Native PHP Attributes** — jobs, middleware, and models now support attribute-based
  configuration. Use `#[Tries]`, `#[Backoff]`, `#[Timeout]` on queue jobs instead of
  class properties. Use `#[Middleware]` on controllers directly.
- **Laravel AI SDK** — ships first-party as of Laravel 13. Provides abstractions for
  text generation, embeddings, and agents. Worth knowing about alongside Prism.
  For this project we still use Prism for structured output — the AI SDK does not
  enforce output schemas the way Prism does, which is critical for graph extraction.
- **Queue routing by class** — use `Queue::route(ProcessCaptureJob::class, connection: 'redis', queue: 'captures')`
  in a service provider instead of hardcoding queue names in the job.
- **`laravel new`** — the installer now creates Laravel 13 by default. No version flag needed.

---

## Local Dev Environment — Laradock

Laradock is the recommended local setup. It provides PHP, Nginx, Redis, and Neo4j
all in one Docker environment. No local installs needed beyond Docker Desktop.

### Initial Setup

```bash
# 1. Create your project folder and clone Laradock inside it
mkdir mindex
cd mindex
git clone https://github.com/Laradock/laradock.git
cd laradock
cp env-example .env
```

### Configure Laradock .env

Open `laradock/.env` and set the following:

```env
# Point Laradock at your Laravel app
APP_CODE_PATH_HOST=../app

# PHP version
PHP_VERSION=8.3

# Neo4j — enable the container
NEO4J=true

# Redis
REDIS_PORT=6379

# Compose project name
COMPOSE_PROJECT_NAME=mindex
```

### Create the Laravel App

```bash
# From the Mindex/ root (not inside laradock/)
# Laravel 13 requires PHP 8.3 — confirm Laradock PHP_VERSION=8.3 in laradock/.env first

# Option A — Laravel installer (recommended, creates Laravel 13 by default as of March 2026)
laravel new app

# Option B — Composer with explicit version pin
composer create-project laravel/laravel:^13.0 app
```

### Start the Containers

```bash
cd laradock

# Start core services — workspace, nginx, php, redis, and neo4j
docker-compose up -d workspace nginx redis neo4j

# Shell into the workspace container to run artisan/composer/npm
docker-compose exec workspace bash
```

### Inside the Workspace Container

```bash
# Install Laravel dependencies
composer require echolabs/prism
composer require laudis/neo4j-php-client
composer require laravel/horizon

# Frontend
npm install
npm install -D vite-plugin-pwa
npm install @vueuse/core

# Generate app key
php artisan key:generate
```

### Neo4j Browser

Once containers are running, Neo4j browser is available at:

```
http://localhost:7474
# Default credentials: neo4j / test (set in laradock/.env NEO4J_AUTH)
```

### Laravel .env — Laradock Service Hostnames

Inside Laradock containers, services talk to each other by container name not localhost.

```env
# Neo4j
NEO4J_HOST=neo4j
NEO4J_PORT=7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=test
NEO4J_DATABASE=neo4j

# Redis (use container hostname inside Docker)
REDIS_HOST=redis

# AI keys
ANTHROPIC_API_KEY=
OPENAI_API_KEY=

# Queue
QUEUE_CONNECTION=redis

APP_NAME=Mindex
APP_URL=http://localhost
```

### Horizon (Queue Dashboard)

```bash
# Inside workspace container
php artisan horizon

# Horizon dashboard available at:
http://localhost/horizon
```

### Daily Dev Workflow

```bash
# Start everything
cd laradock && docker-compose up -d workspace nginx redis neo4j

# Shell in
docker-compose exec workspace bash

# Run queue worker
php artisan horizon

# Run vite dev server (in a second terminal)
docker-compose exec workspace npm run dev
```

### Laradock Services Summary

| Service | Purpose | URL |
|---|---|---|
| nginx | Web server | http://localhost |
| workspace | PHP CLI, Composer, NPM | exec bash |
| redis | Queue backend | redis:6379 |
| neo4j | Graph database | http://localhost:7474 |
| horizon | Queue dashboard | http://localhost/horizon |
| reverb | WebSocket server | ws://localhost:8080 |

---

## What To Build First (Ordered)

1. Enums and DTOs — the schema foundation everything else depends on
2. GraphServiceInterface — define the interface first so GraphService can be stubbed
   during early pipeline development. Fake it with JSON file storage until Neo4j is
   proven stable, then swap in the real implementation without touching the pipeline.
3. GraphService (stubbed) — implement GraphServiceInterface with a simple JSON file
   store. Lets you build and test the full pipeline without Neo4j blocking progress.
4. ExtractionService — Prism call with system prompt, returns WritePayload.
   NOTE: Before implementing, verify whether the Laravel 13 AI SDK now supports
   structured output schema enforcement. If it does, consider using it instead of
   Prism to stay within the Laravel ecosystem. If not, proceed with Prism.
5. IntentValidatorService — validates and overrides AI intent against existing graph
6. Reverb events — PipelineStatusEvent, GraphUpdatedEvent, ConflictDetectedEvent.
   Build these BEFORE the frontend so the UI is wired for real-time from day one.
7. ProcessCaptureJob — wires the full pipeline together. Uses #[Tries(3)] #[Backoff(60)]
   Laravel 13 attributes instead of class properties.
8. CaptureController — receives text and audio, dispatches job
9. TranscribeAudioJob — Whisper call, dispatches ProcessCaptureJob on completion
10. Capture.vue — the main screen, audio recorder, text input, mode toggle.
    Wire Reverb listeners here from the start — do not build UI without them.
11. GraphService (real) — swap JSON stub for full Neo4j implementation via laudis client
12. PWA config — manifest.json, service worker, offline queue
13. Auth — Laravel Sanctum for API token auth. All Reverb channels are private and
    require authenticated sessions. Add auth before any of this goes near a server.
14. DecayConfidenceJob — nightly cron, last thing before v1 is usable

Do not build the graph visualizer until the pipeline is proven working.
The graph view is a nice-to-have. The capture pipeline is the product.

---

## Splitting This Document for AI Coding Tools

This document is intentionally comprehensive but may over-constrain an AI coding
tool if dropped in all at once. Recommended approach:

**Message 1 — Vision + Principles**
Paste only the "What We Are Building", "Core Principles", and "Tech Stack" sections.
Let the AI scaffold the project structure before introducing schema detail.

**Message 2 — Schema**
Paste Enums and DTOs once the project is scaffolded.

**Message 3 — Pipeline**
Paste the Pipeline section when ready to build ProcessCaptureJob.

**Message 4 — Frontend**
Paste Capture.vue spec and Reverb section together when starting the frontend.

Feed context in layers rather than all at once.

---

---

## PWA / Service Worker Features (Phase 2 — build after pipeline is stable)

Do not build these for v1. Get the capture pipeline working first.
Come back to this section once the core loop — capture → extract → graph — is proven.

---

### 1. Offline Capture Queue (most important)

The single feature that makes zero-friction capture actually true.
No signal underground or in a dead zone — capture still works.
The service worker intercepts failed API calls and queues them in IndexedDB.
The moment connectivity returns, Background Sync replays the queue automatically.

```js
// sw.js — intercept failed captures and queue for later
self.addEventListener('fetch', (event) => {
    if (event.request.url.includes('/api/capture')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                queueCaptureForSync(event.request.clone());
                return new Response(JSON.stringify({ queued: true }), {
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
    }
});

// Fires automatically when connection restores
self.addEventListener('sync', (event) => {
    if (event.tag === 'replay-captures') {
        event.waitUntil(replayQueuedCaptures());
    }
});
```

```js
// Capture.vue — register sync tag when capture fails offline
navigator.serviceWorker.ready.then(sw => {
    sw.sync.register('replay-captures');
});
```

---

### 2. App Shell Pre-caching

The entire UI — HTML, JS, CSS, fonts — gets cached on first install.
Every load after that is instant, even on slow connections.
Handled almost entirely by `vite-plugin-pwa` via Workbox. Minimal manual work.

```js
// vite.config.js
VitePWA({
    workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        runtimeCaching: [
            {
                urlPattern: /^https:\/\/fonts\.googleapis\.com/,
                handler: 'CacheFirst',
                options: {
                    cacheName: 'google-fonts',
                    expiration: { maxAgeSeconds: 60 * 60 * 24 * 365 }
                }
            }
        ]
    }
})
```

---

### 3. Push Notifications

Server pushes notifications when the app is closed.
Two moments worth notifying in this app:
- Contradiction detected — *"Something you just said conflicts with a belief from 6 months ago"*
- Morning brief ready — *"Your morning brief is ready"*

```js
// sw.js
self.addEventListener('push', (event) => {
    const data = event.data.json();

    if (data.type === 'conflict') {
        event.waitUntil(
            self.registration.showNotification('Conflicting thought detected', {
                body: data.message,
                icon: '/icons/icon-192.png',
                badge: '/icons/badge.png',
                data: { url: '/interact?highlight=' + data.nodeId },
                actions: [
                    { action: 'view',    title: 'See conflict' },
                    { action: 'dismiss', title: 'Ignore' }
                ]
            })
        );
    }

    if (data.type === 'morning_brief') {
        event.waitUntil(
            self.registration.showNotification('Your morning brief is ready', {
                body: data.summary,
                icon: '/icons/icon-192.png',
                data: { url: '/interact' }
            })
        );
    }
});

// Tap notification → open app to the right screen
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data.url)
    );
});
```

Laravel side — use `webpush` package to send push events from the pipeline:

```php
// Inside ProcessCaptureJob — after ConflictDetectedEvent
$user->notify(new ConflictPushNotification($existingNode, $newNode));
```

---

### 4. Periodic Background Sync — Morning Brief

Register a daily background task that triggers morning brief generation
at a set time, even if the app is closed. Browser support is currently
Chrome/Android only — iOS uses push notifications instead.

```js
// Register in app (not sw.js)
navigator.serviceWorker.ready.then(sw => {
    sw.periodicSync.register('morning-brief', {
        minInterval: 24 * 60 * 60 * 1000 // once per day
    });
});

// sw.js handles the background task
self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'morning-brief') {
        event.waitUntil(
            fetch('/api/graph/morning-brief', { method: 'POST' })
        );
    }
});
```

---

### 5. Offline Indicator

Simple but important — tells the user captures are queued, not lost.
Builds trust in the offline capture queue.

```js
// Capture.vue
const isOnline = ref(navigator.onLine);
window.addEventListener('online',  () => isOnline.value = true);
window.addEventListener('offline', () => isOnline.value = false);

// Show in UI when offline:
// "You're offline — captures are queued and will sync when connected"
```

---

### Phase 2 Build Order (when ready)

1. Offline capture queue + Background Sync — most critical, builds trust
2. App shell pre-caching via vite-plugin-pwa — almost free, do it first
3. Offline indicator in Capture.vue — simple, high impact on trust
4. Push notifications — contradiction alerts first, morning brief second
5. Periodic background sync — morning brief, last because of limited browser support

---

## The One Thing To Get Right

The retrieve-before-write step (Step 3) is the most important thing in the
entire pipeline. If this step is skipped or done poorly, the graph fills with
duplicates and the AI has no basis for detecting evolution or contradiction.

Every AI extraction call must receive the relevant existing graph context.
This is what makes the system learn and adapt rather than going static.

---

*Mindex — personal intelligence layer. Your thinking, remembered.*
*Built on: Laravel 13 · Prism PHP · Neo4j · Vue 3 · Inertia.js · Vite PWA · Laravel Reverb*
