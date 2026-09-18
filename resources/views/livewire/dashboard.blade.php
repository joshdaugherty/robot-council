<div wire:poll.{{ $pollSeconds }}s class="grid gap-4">
    <livewire:robot-council-fleet-presence :poll-seconds="$pollSeconds" />

    <livewire:robot-council-task-board :poll-seconds="$pollSeconds" />
</div>
