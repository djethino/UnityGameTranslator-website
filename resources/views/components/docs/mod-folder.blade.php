@props(['subfolders' => ['']])

{{--
    Where the mod keeps its data — config.json, translations.json, fonts/, images/.

    NOT the same thing as where the mod is installed, and that distinction is the whole reason
    this exists. Under BepInEx the two coincide; under MelonLoader the DLLs go to Mods/ while
    everything the player owns lives in UserData/. The doc used to write these paths out by hand
    in three places, so the configuration section could say "in the mod folder" without ever
    saying which one — the one question a reader has at that moment.

    Pass subfolders to append them: <x-docs.mod-folder :subfolders="['fonts/', 'images/']" />
--}}
<div class="space-y-3">
    <div>
        <p class="font-semibold text-purple-300 mb-1">BepInEx</p>
        @foreach($subfolders as $sub)
            <code class="bg-gray-700 px-2 py-1 rounded text-sm block {{ !$loop->first ? 'mt-1' : '' }}">&lt;Game&gt;/BepInEx/plugins/UnityGameTranslator/{{ $sub }}</code>
        @endforeach
    </div>
    <div>
        <p class="font-semibold text-purple-300 mb-1">MelonLoader</p>
        @foreach($subfolders as $sub)
            <code class="bg-gray-700 px-2 py-1 rounded text-sm block {{ !$loop->first ? 'mt-1' : '' }}">&lt;Game&gt;/UserData/UnityGameTranslator/{{ $sub }}</code>
        @endforeach
    </div>
</div>
