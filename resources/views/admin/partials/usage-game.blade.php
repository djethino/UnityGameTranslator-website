{{-- One game on the Mod usage screen, as its rows know it (ModUsageReport): the site's card, else the
     Steam id the mod sent, else the name it read on disk. One partial for every table of the screen,
     so a game reads the same in each. Expects $row with game, steam_id, game_name. --}}
@if($row['game'])
    <a href="{{ route('games.show', $row['game']) }}" class="hover:text-purple-400">{{ $row['game']->name }}</a>
@elseif($row['steam_id'])
    <a href="{{ \App\Support\StoreLinks::steam($row['steam_id']) }}" target="_blank" rel="noopener" class="hover:text-purple-400"
       title="Not on the site">Steam app {{ $row['steam_id'] }}</a>
@else
    <span title="Not on the site, or the name matches several games">{{ $row['game_name'] }}</span>
@endif
