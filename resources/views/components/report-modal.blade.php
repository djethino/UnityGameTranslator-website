@auth
{{--
    The report dialog, and the only copy of it.

    Any button carrying class="report-btn" and data-report-id="<translation id>" opens it,
    wherever it sits and whenever it appears: the listener is delegated from the document rather
    than bound to the buttons at load, so rows drawn later by Alpine work too. Without that, a
    page that builds its list client-side would show a flag that does nothing.
--}}
<div id="reportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 border border-gray-700">
        <h3 class="text-xl font-semibold mb-4"><i class="fas fa-flag mr-2"></i> {{ __('report.title') }}</h3>
        <form id="reportForm" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('report.reason') }}</label>
                <textarea name="reason" rows="4" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white" placeholder="{{ __('report.placeholder') }}"></textarea>
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

    function close() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.report-btn');
        if (btn) {
            form.action = '{{ url('/report') }}/' + btn.dataset.reportId;
            form.reset();
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
