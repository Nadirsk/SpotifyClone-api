# Run this in an ELEVATED (Administrator) PowerShell window.
# Registers the Laravel queue worker and scheduler as Windows services so the
# background sync pipeline (albums, artists, playlists via CrawlFrontierJob /
# SyncPlaylistsJob / SyncArtistsJob / SyncAlbumsJob) actually runs continuously
# instead of sitting queued and undelivered.

$nssm = "C:\Users\AAIBUZZ 1\AppData\Local\Microsoft\WinGet\Packages\NSSM.NSSM_Microsoft.Winget.Source_8wekyb3d8bbwe\nssm-2.24-101-g897c7ad\win64\nssm.exe"
$appDir = "C:\xampp\htdocs\Music-Streaming\backend"
$php = "C:\xampp\php\php.exe"

New-Item -ItemType Directory -Force -Path "$appDir\storage\logs\services" | Out-Null

# --- Queue worker: consumes the `sync` and `default` queues ---------------
& $nssm install MusicQueueWorker $php "artisan queue:work --queue=sync,default --sleep=3 --max-time=3600"
& $nssm set MusicQueueWorker AppDirectory $appDir
& $nssm set MusicQueueWorker DisplayName "Music Discovery - Queue Worker"
& $nssm set MusicQueueWorker Description "Processes queued jobs (lazy sync, artist/album/playlist sync, crawl frontier). Without this, jobs sit in the jobs table and are never executed."
& $nssm set MusicQueueWorker Start SERVICE_AUTO_START
& $nssm set MusicQueueWorker AppStdout "$appDir\storage\logs\services\queue-out.log"
& $nssm set MusicQueueWorker AppStderr "$appDir\storage\logs\services\queue-err.log"
& $nssm set MusicQueueWorker AppRotateFiles 1
& $nssm set MusicQueueWorker AppRotateBytes 5242880
# queue:work with --max-time exits cleanly every hour to shed accumulated
# memory; tell NSSM that's a normal restart, not a crash to back off from.
& $nssm set MusicQueueWorker AppExitAsync 1
& $nssm set MusicQueueWorker AppRestartDelay 2000

# --- Scheduler: fires CrawlFrontierJob / SyncPlaylistsJob / etc. on their cron ---
& $nssm install MusicScheduler $php "artisan schedule:work"
& $nssm set MusicScheduler AppDirectory $appDir
& $nssm set MusicScheduler DisplayName "Music Discovery - Scheduler"
& $nssm set MusicScheduler Description "Runs routes/console.php's Schedule entries every minute (catalog crawl every 5 min, playlist sync every 30 min, etc). Without this, nothing in that file ever fires."
& $nssm set MusicScheduler Start SERVICE_AUTO_START
& $nssm set MusicScheduler AppStdout "$appDir\storage\logs\services\scheduler-out.log"
& $nssm set MusicScheduler AppStderr "$appDir\storage\logs\services\scheduler-err.log"
& $nssm set MusicScheduler AppRotateFiles 1
& $nssm set MusicScheduler AppRotateBytes 5242880

& $nssm start MusicQueueWorker
& $nssm start MusicScheduler

Start-Sleep -Seconds 3
Get-Service MusicQueueWorker, MusicScheduler, JioSaavnWrapper | Format-Table Name, Status, StartType
