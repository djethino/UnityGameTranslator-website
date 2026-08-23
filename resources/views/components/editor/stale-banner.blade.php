{{--
    « Cette page ne décrit plus le fichier. »

    🔴 **Un bandeau sur la PAGE, pas un avertissement sur un bouton.** Ce qui est périmé, c'est tout
    l'écran : la colonne serveur montre une version qui n'existe plus, et le téléchargement — qui
    reflète fidèlement cet écran — l'est autant. Accroché à `Save`, il n'aurait rien dit à qui
    télécharge.

    ⚠ Il ne touche à rien. Le travail en cours reste intact, et le garde ligne à ligne du serveur
    continue de refuser d'écraser ce qui a bougé : recharger est une proposition, pas une condition.

    ⚠ En haut du contenu, avant les compteurs : c'est là que le regard arrive en revenant sur la page,
    et un avertissement qu'il faut chercher n'est pas un avertissement.
--}}
<div x-show="stale" x-cloak
     class="mb-6 flex flex-wrap items-center gap-3 px-4 py-3 rounded-lg border border-amber-600/60 bg-amber-900/30">
    <i class="fas fa-triangle-exclamation text-amber-400"></i>
    <div class="flex-1 min-w-[16rem] text-sm">
        <p class="text-amber-200 font-medium">{{ __('merge.stale_title') }}</p>
        <p class="text-amber-100/80">{{ __('merge.stale_body') }}</p>
    </div>
    <button type="button" @click="window.location.reload()"
            class="shrink-0 px-3 py-1.5 rounded bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium transition">
        {{ __('merge.stale_reload') }}
    </button>
</div>
