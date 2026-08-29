{{-- Fetch the shared catalogues now instead of waiting for the nightly run.

     🔴 **Only for data that comes from somewhere else.** Everything else on this screen — the tiles,
     the charts, the top games, the version inventory — is recomputed from our own database on every
     load, so reloading the page IS the refresh and a button there would be a second F5. The
     catalogue is fetched on a timer from a source we do not own, and until that timer fires there
     is no way to pick up a change just made to it. That is the rule; it is not "one button per
     card".

     ⚠ **The release list has no button, and that was decided rather than overlooked** (2026-08-29).
     One was added here by symmetry and removed: it refreshes hourly on its own, so a control would
     only ever save that hour.

     ⚠ A verb, not a sentence (`.claude/rules/name-things-in-ui.md`). WHEN to press it goes in the
     tooltip — and it has to be there, because a control that exists on one card out of ten owes the
     reader an answer to "why this one". --}}
<form method="POST" action="{{ route('admin.refresh-catalogues') }}" class="shrink-0"
      x-data="{ running: false }" @submit="running = true">
    @csrf
    <button type="submit"
            :disabled="running"
            class="text-xs px-2.5 py-1 rounded border border-gray-600 text-gray-300 hover:bg-gray-700
                   disabled:opacity-50 disabled:cursor-wait transition"
            title="Fetch the catalogues now. Worth doing after changing them: the site keeps serving the copy it last fetched — correct, but frozen — until the next scheduled run. Goes out to the network, so it can take a few seconds.">
        <i class="fas fa-rotate mr-1" :class="running && 'fa-spin'"></i>
        <span x-text="running ? 'Fetching…' : 'Refresh'">Refresh</span>
    </button>
</form>
