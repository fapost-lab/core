---
id: adr-20261120-one-time-codes-for-auth-request
type: adr
status: accepted
date: 2026-11-20
domains:
  - flow
paths:
  - "app/Domains/Flow/Auth/**"
  - app/Domains/Flow/Handlers/AuthRequestV2NodeHandler.php
  - app/Jobs/Flow/SendOneTimeCodeJob.php
summary: "auth_request@2 proves phone or address ownership: Core generates one-time codes and keeps only their HMAC in the cache, counters in system.auth.<node>, delivery through Foundation's OneTimeCodeSenderInterface (Core email default, SMS from connectors), limits per node, destination and quota"
reviewed_at: 2026-11-20
---
# `auth_request@2` proves ownership of a phone number or address with one-time codes Core generates and hashes, delivered through a Foundation sender contract

Approved by the owner at the design gate of task `flow-auth-request` (decisions D1–D10 as recommended).

## Context

`auth_request@1` could only compare a variable with an expected value, so a flow could not check that a contact owns
a phone number or an email address. Core had no SMS provider, no path for mail to a contact, and no place for a secret
that a node waits on: `flow.*` is readable and writable by every node (`assign` included), the session state and the
node trace are visible to staff, and the trace masker is a deny-list. An SMS or an email that left cannot be recalled
and costs the tenant money; a bot that sends codes to any address a contact types is a pumping and bombing tool.

## Decision

- A new handler version, `auth_request@2` (`basic`, `phone`, `sms_code`, `email_code`; outputs `authenticated` and
  `failed`, the reason in metadata). `@1` stays registered unchanged.
- `phone` accepts only the contact's own shared card (`user_id` equals the sender); a configured expected number must
  render to a valid number and match in E.164.
- Core generates the code (`random_int`) and keeps only an HMAC under a key derived from `APP_KEY` in the cache
  (`AuthCodeStore`), compared with `hash_equals`, marked used on success and dropped on every `failed` exit. Counters live in `system.auth.<node>`,
  writable only by `auth_request` (`SystemStateNamespacePolicy`). Each entry into the node is a numbered challenge;
  its sends are `<challenge>:<send>`, which key the stored record, the job guard and the quota unit.
- Delivery goes through Foundation 0.45 `Auth\Contracts\OneTimeCodeSenderInterface`, one sender per channel in Core's
  `OneTimeCodeSenderRegistryInterface`, which packages fill from `boot()`. Core's default email sender mails a fixed,
  localized text (`lang/*/auth_code.php`) signed with the assistant's name through the platform mailer, and is absent
  while that mailer only logs. SMS has no Core sender: a connector brings one.
- The code reaches the sender only in `SendOneTimeCodeJob` (encrypted, `messaging.system`, one attempt behind a guard).
- Limits: per node (attempts, sends, cooldown, TTL, all bounded), 5 codes an hour per destination and tenant, and the
  per-period quota keys `auth_code_sms` / `auth_code_email`.

## Alternatives

- **SMS and email directly in Core** (email from a Core job, SMS as a templated HTTP call with a saved credential):
  the code would travel as ordinary `call` parameters that the trace masks only by key name, every provider differs,
  and the SaaS operator could not replace or meter the email path. Rejected.
- **The node only generates the code; the author sends it** (`call`, `notify`, `send_message`): the code sits in
  `flow.*`, readable by any node and visible in the state, the trace and the chat. Rejected.
- **The sender contract inside Core first**, moved to Foundation with the first external sender: cheaper now, a
  breaking move later, while the operator package and SMS connectors are exactly the senders that live outside.
  Rejected (D2).
- **Registration through `CoreRegistrarInterface`**: that is the Solution registrar, which connectors and operator
  packages never receive; a Foundation registry interface resolved at boot, as `LimitRegistryInterface`, is the
  existing pattern.

## Consequences

- Core needs Foundation `^0.45`. SMS codes stay unpublishable until a connector registers an SMS sender.
- Mail to contacts goes out from the platform's address; its text is fixed in Core's lang files, so a contact language
  Core has no file for receives the fallback language (review finding F7, left to the owner).
- `is_authenticated` still never expires (D5) and `input` of type `contact` still accepts a forwarded card (D10):
  both are separate tasks.
- A registered sender lives as long as the worker: the contract forbids it to hold tenant data or scoped services.
