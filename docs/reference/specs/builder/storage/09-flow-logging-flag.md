# 09 · Flow `logging_enabled` flag + per-session history

**Layer:** backend (PHP) + Filament admin

## Purpose

A per-flow boolean `logging_enabled` (default `false`). When it is on, the runtime writes detailed
rows into `flow_session_history` for every session of that flow. When it is off, only the base
execution logs (`flow_logs`) are written.

## What is built

- **Schema.** `flow_definitions.logging_enabled` comes from
  `2026_05_06_000001_add_expression_engine_and_logging_to_flow_definitions.php`;
  `flow_drafts.logging_enabled` from `2026_05_08_000001_add_logging_enabled_to_flow_drafts.php`;
  the history table from `2026_05_06_000005_create_flow_session_history_table.php`.
- **Publish.** `PublishFlowService` copies `draft.logging_enabled` into the published definition.
- **Writer selection.** `HistoryWriterFactory::for(FlowDefinition)` returns `DefaultHistoryWriter`
  when `logging_enabled` is true and `NoOpHistoryWriter` otherwise. A writer failure is logged
  (`flow.history.write_failed`) and never breaks execution.
- **Recorded events.** `HistoryEventType` (from `fapost/foundation`) values written by the platform today:
  `state_change` (after the engine applies a node's state changes and from `ContactWriter`),
  `subflow_started` and `subflow_returned`.
- **Subflows.** Each definition has its own flag. The parent's flag decides whether `subflow_started` /
  `subflow_returned` are written to the parent session; the child's internals are written only if the
  child definition has logging enabled too.
- **Admin toggle.** `FlowFormSchema` (Filament Assistant, Flows form) has the `logging_enabled` toggle with a
  helper text. The Vue builder has no settings panel; the flag is edited only in Filament.
- **Session detail.** `FlowSessionInfolistSchema` shows a "History" section (timestamp, node, event, path,
  payload) when the flow has logging enabled or the session already has history entries.

## Not built

- A "Logged" column in the FlowSession list (`FlowSessionsTable`).
- Drill-down from history rows to child sessions.
- Dedicated event types for user input, button choice, API result mapping and RAG results; these changes
  are visible only as `state_change` entries.
- Retention and partitioning policy for `flow_session_history`.
- Per-node logging override and a report builder over group attributes.

## Files

- `app/Domains/Flow/History/{HistoryWriterFactory,DefaultHistoryWriter,NoOpHistoryWriter,HistoryWriterInterface}.php`
- `app/Domains/Flow/Models/{FlowDefinition,FlowDraft,FlowSessionHistoryEntry}.php`
- `app/Domains/Flow/Services/PublishFlowService.php`
- `app/Filament/Assistant/Resources/Flows/Schemas/FlowFormSchema.php`
- `app/Filament/Assistant/Resources/FlowSessions/Schemas/FlowSessionInfolistSchema.php`
- `lang/{en,ru,uk}/assistant.php`
