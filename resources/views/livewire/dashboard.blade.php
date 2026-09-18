{{--
    No `wire:poll` here. Each panel polls itself, and a parent refresh does not re-execute a child
    component -- Livewire spoofs an already-rendered child into a placeholder -- so a poll on this
    element would be a round trip that changes nothing.
--}}
<div class="grid gap-4">
    <livewire:robot-council-fleet-presence :poll-seconds="$pollSeconds" />

    <livewire:robot-council-task-board :poll-seconds="$pollSeconds" />

    <livewire:robot-council-change-feed :poll-seconds="$pollSeconds" />
</div>
