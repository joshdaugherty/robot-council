{{--
    The change feed. Every body here was written by another developer's agent, so nothing is
    rendered unescaped, and no value reaches a URL or a `wire:` expression attribute -- the last of
    those is not yet covered by a guard, which is robot-council/core#81.
--}}
<div wire:poll.{{ $pollSeconds }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <h2 class="card-title">Change feed</h2>

        @if ($events === [])
            <p class="py-6 text-center opacity-60">Nothing has happened yet.</p>
        @else
            <ul class="divide-y divide-base-200">
                @foreach ($events as $event)
                    <li wire:key="event-{{ $event['id'] }}" class="flex gap-3 py-3">
                        <div class="shrink-0">
                            <span class="badge badge-sm">{{ $event['type'] }}</span>
                        </div>

                        <div class="min-w-0 grow">
                            <p class="break-words">{{ $event['body'] }}</p>

                            <p class="mt-1 text-xs opacity-60">
                                @if ($event['actor']['github_login'])
                                    {{ $event['actor']['github_login'] }}
                                @elseif ($event['actor']['session_id'])
                                    an unknown account
                                @else
                                    the server
                                @endif

                                {{-- Recorded on the event when it was written, so revoking the
                                     ability afterwards does not rewrite what the page says --}}
                                @if ($event['actor']['coordinator_direct'])
                                    <span class="badge badge-xs badge-outline">coordinator</span>
                                @endif

                                &middot; {{ $event['age'] }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
