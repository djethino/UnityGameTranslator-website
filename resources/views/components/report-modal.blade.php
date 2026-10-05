@auth
{{--
    The report dialog, and the only copy of it — for a translation and, since 2026-10-05, for a game
    card.

    Any button carrying class="report-btn" and data-report-id="<translation id>" opens it for a
    translation; data-report-game="<game id>" opens it for a game, with the question of what is
    wrong with the card. The listener is delegated from the document rather than bound to the
    buttons at load, so rows drawn later by Alpine work too. Without that, a page that builds its
    list client-side would show a flag that does nothing.

    ⚠ For a game, details are asked for only when a person has to read the report: an adult-content
    report is answered by the stores first (ReportController::storeGame), so its text is optional.
--}}
<div id="reportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 border border-gray-700">
        <h3 class="text-xl font-semibold mb-4">
            <i class="fas fa-flag mr-2"></i>
            <span data-report-for="translation">{{ __('report.title') }}</span>
            <span data-report-for="game" hidden>{{ __('report.game_title') }}</span>
        </h3>
        <form id="reportForm" method="POST">
            @csrf
            <fieldset data-report-for="game" hidden class="mb-4">
                <legend class="block text-sm font-medium text-gray-300 mb-2">{{ __('report.game_kind') }}</legend>
                <div class="space-y-2">
                    @foreach(\App\Models\Report::GameKinds as $kind)
                        <label class="flex items-start gap-2 text-sm text-gray-200 cursor-pointer">
                            <input type="radio" name="kind" value="{{ $kind }}" class="mt-0.5 text-purple-600 bg-gray-700 border-gray-600">
                            <span>{{ __('report.kind_' . $kind) }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300 mb-2">
                    <span data-report-for="translation">{{ __('report.reason') }}</span>
                    <span data-report-for="game" hidden>{{ __('report.details') }}</span>
                </label>
                <textarea name="reason" rows="4" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white" placeholder="{{ __('report.placeholder') }}"></textarea>
                <p data-report-optional hidden class="text-xs text-gray-500 mt-1">{{ __('report.details_optional') }}</p>
            </div>
            <div class="flex gap-3">
                <button type="button" id="closeReportModalBtn" class="flex-1 bg-gray-600 hover:bg-gray-500 text-white py-2 rounded-lg">{{ __('report.cancel') }}</button>
                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg">{{ __('report.submit') }}</button>
            </div>
        </form>
    </div>
</div>
<script nonce="{{ $cspNonce }}">
(function() {
    var modal = document.getElementById('reportModal');
    var form = document.getElementById('reportForm');
    var reason = form.querySelector('textarea[name="reason"]');
    var optional = form.querySelector('[data-report-optional]');
    var kinds = form.querySelectorAll('input[name="kind"]');
    var translationHint = reason.placeholder;

    function close() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // What the dialog is about: the parts of the other subject are hidden, and their fields stop
    // being asked for — a hidden required field would block the form with no way to see why.
    function show(subject) {
        modal.querySelectorAll('[data-report-for]').forEach(function(el) {
            el.hidden = el.dataset.reportFor !== subject;
        });
        kinds.forEach(function(input) { input.required = subject === 'game'; });
        // The hint asks why a TRANSLATION is reported: on a game, the label says it all.
        reason.placeholder = subject === 'game' ? '' : translationHint;
        detailsFor(null);
    }

    // Details are optional for the two adult-content kinds: the stores answer those first.
    function detailsFor(kind) {
        var isOptional = kind === 'adult' || kind === 'not_adult';
        reason.required = !isOptional;
        optional.hidden = !isOptional;
    }

    form.addEventListener('change', function(e) {
        if (e.target.name === 'kind') detailsFor(e.target.value);
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.report-btn');
        if (btn) {
            form.reset();
            if (btn.dataset.reportGame) {
                form.action = '{{ url('/report/game') }}/' + btn.dataset.reportGame;
                show('game');
            } else {
                form.action = '{{ url('/report') }}/' + btn.dataset.reportId;
                show('translation');
            }
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            return;
        }
        if (e.target === modal || e.target.closest('#closeReportModalBtn')) close();
    });

    // Escape closes it, like every other dialog on this site
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) close();
    });
})();
</script>
@endauth
