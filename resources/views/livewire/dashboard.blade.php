<div wire:poll.{{ $pollSeconds }}s>
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">The fleet</h2>

            <p class="opacity-70">
                The task queue, agent presence, held locks, and the change feed arrive here as they
                are built. This page refreshes every {{ $pollSeconds }} seconds.
            </p>
        </div>
    </div>
</div>
