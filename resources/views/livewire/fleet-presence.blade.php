{{--
    Presence and locks. Every value here was supplied by an agent or derived from one, so nothing is
    rendered unescaped and nothing reaches a URL or a `wire:` expression attribute -- the last of
    those is not yet covered by a guard, which is robot-council/core#81.
--}}
<div wire:poll.{{ $pollSeconds }}s class="grid gap-4 lg:grid-cols-2">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Agents</h2>

            @if ($sessions === [])
                <p class="py-6 text-center opacity-60">No agent has enrolled yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Developer</th>
                                <th>Machine</th>
                                <th>Status</th>
                                <th>Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sessions as $session)
                                <tr wire:key="session-{{ $session['id'] }}">
                                    <td>{{ $session['github_login'] ?? 'an unknown account' }}</td>

                                    <td>
                                        <div>{{ $session['machine_label'] ?? 'an unknown machine' }}</div>
                                        <div class="text-xs opacity-60">{{ $session['harness'] ?? '' }}</div>
                                    </td>

                                    {{-- Read from the row, which #24 made the decision, rather than
                                         re-derived from the contact time --}}
                                    <td>
                                        <span class="badge badge-sm">{{ $session['status'] }}</span>
                                    </td>

                                    <td class="whitespace-nowrap text-xs opacity-70">
                                        {{ $session['seconds_since_contact'] }}s ago
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h2 class="card-title">Locks</h2>

            @if ($locks === [])
                <p class="py-6 text-center opacity-60">Nothing is locked.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Lock</th>
                                <th>Held by</th>
                                <th>Fence</th>
                                <th>Lease</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($locks as $lock)
                                <tr wire:key="lock-{{ $lock['fence'] }}-{{ $loop->index }}">
                                    <td class="font-medium">{{ $lock['name'] }}</td>

                                    <td>
                                        @if ($lock['holder'])
                                            {{ $lock['holder']['github_login'] ?? 'an unknown account' }}
                                        @else
                                            <span class="opacity-60">nobody</span>
                                        @endif
                                    </td>

                                    <td>{{ $lock['fence'] }}</td>

                                    {{-- A lapsed lease is shown rather than hidden: a row that
                                         still names a holder whose lease has run out is exactly
                                         what a developer is looking for --}}
                                    <td class="whitespace-nowrap text-xs {{ $lock['held'] ? 'opacity-70' : 'text-warning' }}">
                                        {{ $lock['lease'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
