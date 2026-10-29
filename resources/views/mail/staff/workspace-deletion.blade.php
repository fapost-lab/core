<x-mail::message>
# {{ __('staff.workspace_deletion.greeting', ['name' => $recipientName]) }}

@if ($cancelled)
{{ __('staff.workspace_deletion.cancelled.body', ['workspace' => $workspace, 'cancelled_by' => $cancelledBy]) }}

{{ __('staff.workspace_deletion.cancelled.requested', ['requested_by' => $requestedBy, 'requested_at' => $requestedAtLabel]) }}

{{ __('staff.workspace_deletion.cancelled.nothing_lost') }}
@else
{{ __('staff.workspace_deletion.scheduled.body', ['workspace' => $workspace, 'requested_by' => $requestedBy, 'requested_at' => $requestedAtLabel]) }}

**{{ __('staff.workspace_deletion.scheduled.starts', ['starts_at' => $startsAtLabel]) }}**

{{ __('staff.workspace_deletion.scheduled.irreversible') }}

{{ __('staff.workspace_deletion.scheduled.cancel_hint') }}

<x-mail::button :url="$settingsUrl">
{{ __('staff.workspace_deletion.scheduled.cancel_button') }}
</x-mail::button>
@endif

</x-mail::message>
