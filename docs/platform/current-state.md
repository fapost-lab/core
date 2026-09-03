# Current Project State

This document exists to separate what is already implemented from the target architecture.

## What Exists Today

The application is an active Laravel 12 Core implementation, not a starter scaffold. The repository currently contains:

- domain modules under `app/Domains/*`;
- landlord and tenant migrations;
- tenant-aware webhook routing and channel registry infrastructure;
- assistant, contact, staff, media, messaging, and shared model infrastructure;
- a versioned Flow Engine with handlers, validation, publish flow, sessions, logs, concurrency primitives, and routing;
- a Vue/Inertia builder under `resources/js/builder`;
- Filament administration surfaces;
- local `fapost-foundation` and `fapost-support` packages;
- PHPUnit tests and PHPat architecture rules executed through PHPStan.

## What Is Confirmed by Configuration

- Default database: PostgreSQL
- Laravel version: 12
- PHP version: 8.4
- Tenant model: landlord connection plus tenant connection/schema
- Redis is part of the runtime design for queues, locks, cache, and webhook registry
- Horizon, Filament, Inertia, Vue, Vite, and Tailwind are present in the application stack
- Webhook ingress runs on PHP-FPM; an optional Go gateway in `gateway/` can take it over

## What Does Not Exist Yet

These areas are planned or partial and should not be documented as finished product features:

- `app/Features/*` built-in feature lifecycle;
- external Solution/Plugin lifecycle beyond current contracts and conventions;
- Conversation Logging domain (`conversations`, `conversation_messages`, reader/store ports, capture pipeline);
- managed Broadcasting entities (`broadcasts`, `broadcast_recipients`) and analytics UI;
- Contact Segments (`contact_segments`, `ContactSegmentResolver`);
- Knowledge Base/RAG product layer (`knowledge_bases`, `knowledge_documents`, provider adapters, Filament resource);
- full event-chain start pipeline after `emit_event`;
- production WhatsApp adapter behavior;
- product-complete enforcement for every target architecture rule described in `docs/platform/architecture`.

## Partially Implemented Or Easy To Misread

- `MessageRouter`, `DropPolicy`, `SessionStateRouter`, typing integration, and `/reset` command handling exist.
- `EmitEventNodeHandler` exists, but event fan-out currently resolves/logs triggers rather than starting subscribed flows.
- `RagAdapterRegistry`, `RagQueryNodeHandler`, and validation exist, but no provider or knowledge-base storage is wired.
- Loop runtime/builder work exists, including `loop_end` management and execution budget. Some array/property edge cases
  are still tracked in `docs/platform/TASKS.md`.
- `BroadcastSendJob` exists as low-level fan-out plumbing; it is not the same as a complete managed Broadcast feature.
- PHPat architecture rules live under `tests/Architecture` and are executed by PHPStan. The default PHPUnit suite reaches
  them through `tests/Unit/Architecture/MigrationTest.php`; running `php artisan test tests/Architecture` directly is
  not the correct command.

## Developer Implication

When adding new parts to the project, explicitly mark:

- what belongs to the current implementation;
- what is being introduced as a step toward the target architecture;
- which decisions are already fixed as mandatory rules.

Do not describe target architecture as implemented until the code exists. Conversely, do not leave implemented systems
documented as future work; use "partial" when a skeleton or runtime primitive exists but the product feature is not
complete.
