@props(['redirect' => null])

{{--
    Every way there is of signing in, written ONCE.

    🔴 **Because it was written three times, and only one copy was kept up.** When local accounts
    arrived — a username and a password, no platform, no email — they were added to /login and
    nowhere else. /link, the page the MOD opens to connect an account, went on offering five
    providers and no way in for anybody who had created one; so did the mobile menu. Nothing could
    report it: three copies of a list are three lists.

    ⚠ The local account comes last, after the platforms, and that order is deliberate: most people
    arrive with a Steam or Discord account already, and the local one exists for those who want no
    platform at all. Last is not lesser — it is the one that needs the sentence beside it.

    @param redirect  Where to come back to once signed in. /link passes its own address, so the
                     code somebody is holding is still in front of them afterwards.
--}}

@php
    $redirectParam = $redirect ? '?redirect=' . urlencode($redirect) : '';
@endphp

{{-- Staying signed in across browser restarts, asked once and governing every way in.

     🔴 It used to happen without asking: both sign-in paths passed `remember: true`, so everybody
     got a five-year cookie whether they wanted one or not — including on a machine that was not
     theirs. Unticked, the session lasts as long as the browser is open and ends when it closes.

     ⚠ It sits ABOVE the methods because it governs all of them, and it is deliberately not ticked:
     a box already ticked gets past somebody exactly on the day it matters, which is the day they
     are signing in somewhere that is not their own machine. --}}
<label class="flex items-start gap-3 mb-5 text-left cursor-pointer">
    <input type="checkbox" id="auth-remember" {{ old('remember') ? 'checked' : '' }}
           class="mt-0.5 rounded bg-gray-700 border-gray-600 text-purple-500 focus:ring-purple-500">
    <span class="text-sm text-gray-300">
        {{ __('auth.remember_me') }}
        <span class="block text-gray-500 text-xs mt-0.5">{{ __('auth.remember_me_hint') }}</span>
    </span>
</label>

<!-- Gaming platforms -->
<div class="grid grid-cols-2 gap-3 mb-4">
    <a href="{{ route('auth.redirect', 'steam') }}{{ $redirectParam }}" class="js-auth-provider flex items-center justify-center gap-2 bg-gray-800/80 hover:bg-gray-700 text-white px-4 py-3 rounded-lg border border-gray-600/50 transition hover:-translate-y-0.5">
        <i class="fab fa-steam text-lg"></i>
        <span class="text-sm font-medium">Steam</span>
    </a>
    {{-- Epic Games: hidden until app approval --}}
    {{-- <a href="{{ route('auth.redirect', 'epicgames') }}{{ $redirectParam }}" class="js-auth-provider flex items-center justify-center gap-2 bg-black/80 hover:bg-gray-900 text-white px-4 py-3 rounded-lg border border-gray-600/50 transition hover:-translate-y-0.5">
        <img src="https://cdn.simpleicons.org/epicgames/white" alt="" class="w-4 h-4">
        <span class="text-sm font-medium">Epic</span>
    </a> --}}
</div>

<!-- Other providers -->
<div class="grid grid-cols-2 gap-3">
    <a href="{{ route('auth.redirect', 'discord') }}{{ $redirectParam }}" class="js-auth-provider flex items-center justify-center gap-2 bg-indigo-600/90 hover:bg-indigo-600 text-white px-4 py-3 rounded-lg transition hover:-translate-y-0.5">
        <i class="fab fa-discord text-lg"></i>
        <span class="text-sm font-medium">Discord</span>
    </a>
    <a href="{{ route('auth.redirect', 'twitch') }}{{ $redirectParam }}" class="js-auth-provider flex items-center justify-center gap-2 bg-purple-600/90 hover:bg-purple-600 text-white px-4 py-3 rounded-lg transition hover:-translate-y-0.5">
        <i class="fab fa-twitch text-lg"></i>
        <span class="text-sm font-medium">Twitch</span>
    </a>
    <a href="{{ route('auth.redirect', 'github') }}{{ $redirectParam }}" class="js-auth-provider flex items-center justify-center gap-2 bg-gray-700/90 hover:bg-gray-600 text-white px-4 py-3 rounded-lg transition hover:-translate-y-0.5">
        <i class="fab fa-github text-lg"></i>
        <span class="text-sm font-medium">GitHub</span>
    </a>
    <a href="{{ route('auth.redirect', 'google') }}{{ $redirectParam }}" class="js-auth-provider flex items-center justify-center gap-2 bg-red-600/90 hover:bg-red-600 text-white px-4 py-3 rounded-lg transition hover:-translate-y-0.5">
        <i class="fab fa-google text-lg"></i>
        <span class="text-sm font-medium">Google</span>
    </a>
</div>

<!-- Local account (anonymity-first, no platform, no email) -->
<div class="flex items-center gap-3 my-6">
    <div class="flex-1 border-t border-gray-700"></div>
    <span class="text-gray-500 text-xs uppercase">{{ __('auth.or_local') }}</span>
    <div class="flex-1 border-t border-gray-700"></div>
</div>

<form method="POST" action="{{ route('local.login') }}" class="text-left space-y-3">
    @csrf
    {{-- Carried through the form too, not only on the provider links: signing in with a username
         must land where signing in with Steam lands. --}}
    @if ($redirect)
        <input type="hidden" name="redirect" value="{{ $redirect }}">
    @endif
    {{-- The box lives above, outside this form, because it governs the platform buttons too. Its
         answer is mirrored here so the form carries it on its own — with no script at all, this
         still posts "0", which is the safe answer rather than a silently ignored one. --}}
    <input type="hidden" name="remember" id="auth-remember-mirror" value="{{ old('remember') ? '1' : '0' }}">
    <input type="text" name="username" required maxlength="24" value="{{ old('username') }}"
           placeholder="{{ __('auth.username') }}" autocomplete="username"
           class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white placeholder-gray-500 focus:outline-none focus:border-purple-500">
    <input type="password" name="password" required maxlength="200"
           placeholder="{{ __('auth.password') }}" autocomplete="current-password"
           class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white placeholder-gray-500 focus:outline-none focus:border-purple-500">
    @error('username')<p class="text-red-400 text-sm">{{ $message }}</p>@enderror
    <button type="submit" class="w-full bg-gray-700 hover:bg-gray-600 text-white font-medium px-4 py-2.5 rounded-lg transition">
        <i class="fas fa-user-shield mr-1"></i> {{ __('auth.sign_in_local') }}
    </button>
</form>

<div class="flex justify-between text-sm mt-3">
    <a href="{{ route('local.register') }}" class="text-purple-400 hover:text-purple-300 transition">{{ __('auth.create_local') }}</a>
    <a href="{{ route('local.recover') }}" class="text-gray-500 hover:text-gray-300 transition">{{ __('auth.lost_password') }}</a>
</div>

{{-- The platform buttons are plain links, so the answer has to be written into them. Without this
     script the box still works for the username form, and a platform sign-in simply does not
     remember — the safe outcome, never a control that pretends to act. --}}
<script nonce="{{ $cspNonce }}">
(function () {
    var box = document.getElementById('auth-remember');
    var mirror = document.getElementById('auth-remember-mirror');
    if (!box) return;

    function apply() {
        if (mirror) mirror.value = box.checked ? '1' : '0';

        document.querySelectorAll('a.js-auth-provider').forEach(function (link) {
            var url = new URL(link.href, window.location.origin);
            box.checked ? url.searchParams.set('remember', '1') : url.searchParams.delete('remember');
            link.href = url.toString();
        });
    }

    box.addEventListener('change', apply);
    apply();
})();
</script>
