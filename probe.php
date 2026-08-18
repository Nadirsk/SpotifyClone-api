<?php
echo 'genres=', DB::table('genres')->count(), PHP_EOL;
echo 'albums_with_lang=', DB::table('albums')->whereNotNull('language_id')->count(), PHP_EOL;
echo 'songs_with_album=', DB::table('songs')->whereNotNull('album_id')->count(), PHP_EOL;
echo '--- top languages by song count ---', PHP_EOL;
$rows = DB::table('songs')
    ->join('languages', 'languages.id', '=', 'songs.language_id')
    ->selectRaw('languages.name, count(*) c')
    ->groupBy('languages.name')->orderByDesc('c')->limit(18)->get();
foreach ($rows as $r) { echo sprintf('%-16s %d', $r->name, $r->c), PHP_EOL; }
echo '--- languages with zero songs ---', PHP_EOL;
echo DB::table('languages')->whereNotIn('id', function ($q) { $q->select('language_id')->from('songs')->whereNotNull('language_id'); })->count(), PHP_EOL;
