---
id: adr-20261125-inbound-provider-kind-from-connector
type: adr
status: accepted
date: 2026-11-25
domains:
  - media
  - channels
  - conversation
paths:
  - app/Domains/Channels/Telegram/TelegramInboundNormalizer.php
  - app/Domains/Flow/Handlers/InputNodeHandler.php
  - app/Domains/Conversation/Capture/ConversationCaptureFactory.php
  - app/Domains/Media/Services/MediaIngestor.php
summary: Why the inbound provider kind is reported per file by the connector on IncomingMedia::$providerKind (Foundation 0.49) and never derived from IncomingMessageType; null when unsaid
---
# The connector reports the provider kind of each incoming file on `IncomingMedia::$providerKind`; Core never derives it from the message type

## Context

A cached channel file id has to be sent back by the method that minted it (a Telegram voice id through `sendAudio`
is refused), so Core keeps the provider kind with the id (`media_channel_refs.provider_kind`, `provider_kind` in the
transcript descriptor). On the way out the uploader reports it (`UploadResult::$providerKind`). On the way in, Core
used to mint it from `IncomingMessageType::value` in `InputNodeHandler` and `ConversationCaptureFactory` — right for
Telegram by coincidence, a guess for every other connector, and unable to express provider kinds outside that enum
(finding F12 of `telegram-media-file-id-kinds-findings`). Approved at the design gate by the owner ("Принимаю твои
решения", 2026-11-23): the recommended option of every question.

## Decision

- **Foundation 0.49** adds `DTO\IncomingMedia::$providerKind` (`?string`, last constructor argument, default null),
  in the vocabulary of `UploadResult::$providerKind`. It is per file, not per message.
- The connector's `normalize()` fills it. Telegram's normalizer sets the field the file came in (`photo`, `document`,
  `video`, `voice`, `audio`).
- Core stores what was reported (`MediaIngestor` via `InputNodeHandler`, the transcript descriptor via
  `ConversationCaptureFactory`) and never falls back to `IncomingMessageType`; null means the connector did not say.
- `connector-slack` does not change: it serves media through a link policy and never sends by a cached id.

## Alternatives

- **A field on `IncomingMessage`.** One kind per message, but a message may carry several files of different kinds
  (Slack). Rejected.
- **A Core-side per-channel kind resolver.** No Foundation change, but a new interface apart from the data the
  normalizer already holds, and the kind computed in two places. Rejected.
- **Document the limitation only.** Free, but the next connector with an uploader inherits a guessed kind. Rejected.
- **Fall back to the message type only for `ReportsProviderKind` uploaders.** Core-only, but keeps deriving the kind
  from the message type, which is what the finding asked to remove. Rejected.

## Consequences

- A connector that does not report a kind gets refs with `provider_kind = null`: an uploader without the
  `ReportsProviderKind` marker keeps the ref and sends by MIME (`DeliveryKind`); one with the marker treats it as a
  cache miss and uploads once. Today only Telegram has the marker, and its normalizer reports the kind.
- Core needs `fapost/foundation` `^0.49`; the release order is the Foundation tag, then Core.
- Inbound kinds Core cannot yet send by (`animation`, `video_note`, `sticker`) become expressible; sending them is a
  separate change (`DeliveryKind::PAYLOAD_TYPES`, `TelegramDeliveryResolver`).
- No migration or backfill: refs and descriptors stored before stay as they are.
- This amends the meaning of `provider_kind` in decision 9 of `adr-conversation-transcript-design`
  (`docs/reference/specs/messaging/conversation-logging.md`), which still reads "how the message carried the file":
  the descriptor's `provider_kind` is the kind the connector reported on `IncomingMedia::$providerKind`, null when it
  did not say. The rest of that decision stands; the accepted record is left as written.
