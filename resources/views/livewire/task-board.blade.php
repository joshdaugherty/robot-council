{{--
    The queue. Every value here comes from another developer's agent, so nothing is rendered
    unescaped and no agent-supplied value reaches a URL attribute -- the #67 and #70 guards refuse
    both, and this is the page they were written for.
--}}
<div wire:poll.{{ $pollSeconds }}s class="card bg-base-100 shadow-sm">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="card-title">Queue</h2>

            <div class="flex flex-wrap gap-1">
                <button type="button" wire:click="showStatus('')"
                    class="btn btn-xs {{ $status === '' ? 'btn-active' : 'btn-ghost' }}">All</button>

                @foreach ($statuses as $option)
                    <button type="button" wire:click="showStatus('{{ $option->value }}')"
                        class="btn btn-xs {{ $status === $option->value ? 'btn-active' : 'btn-ghost' }}">
                        {{ $option->value }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($tasks === [])
            <p class="py-6 text-center opacity-60">Nothing in the queue.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Filed by</th>
                            <th>Held by</th>
                            <th>Age</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            <tr wire:key="task-{{ $task['id'] }}">
                                <td>
                                    <div class="font-medium">{{ $task['title'] }}</div>

                                    @if ($task['project_id'])
                                        <div class="text-xs opacity-60">{{ $task['project_id'] }}</div>
                                    @endif
                                </td>

                                <td><span class="badge badge-sm">{{ $task['status'] }}</span></td>

                                <td>{{ $task['priority'] }}</td>

                                <td>
                                    @if ($task['created_by'])
                                        {{ $task['created_by']['github_login'] ?? 'an unknown account' }}

                                        {{-- #16 decides who may claim this, and the flag is what was
                                             true when the task was filed rather than now --}}
                                        @if ($task['created_by']['coordinator_direct'] ?? false)
                                            <span class="badge badge-sm badge-outline">coordinator</span>
                                        @endif
                                    @else
                                        <span class="opacity-60">a session since deleted</span>
                                    @endif
                                </td>

                                <td>
                                    @if ($task['claimed_by'])
                                        {{ $task['claimed_by']['github_login'] ?? 'an unknown account' }}
                                    @else
                                        <span class="opacity-60">nobody</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap text-xs opacity-70">
                                    {{ $task['created_at'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                @if ($afterId !== null)
                    <button type="button" wire:click="showFirst" class="btn btn-sm btn-ghost">First page</button>
                @endif

                @if ($cursor && count($tasks) === \RobotCouncil\Livewire\TaskBoard::PER_PAGE)
                    <button type="button"
                        wire:click="showNext({{ $cursor['priority'] }}, {{ $cursor['id'] }})"
                        class="btn btn-sm">Next page</button>
                @endif
            </div>
        @endif
    </div>
</div>
