<?php

declare(strict_types=1);

/**
 * timer — countdown with a real audible alarm at the end.
 *
 *   make timer <duration> [message...]   start: 1:30:00 | 25:00 | 90s | 25m | 1h30m | 300
 *   make timer continue                  resume the saved timer (closed terminal, reboot)
 *   make timer cancel                    forget the saved timer
 *
 * Stopping the countdown in ANY way (Ctrl+C, closed terminal, crash, reboot) just
 * stops the clock: the remaining time is persisted every second, and `continue`
 * resumes from exactly where it stopped — elapsed real time never counts.
 * The alarm sound repeats until Enter (or Ctrl+C); the desktop notification is
 * closed together with the alarm. A Telegram message is sent too when a bot token
 * and chat id are configured in .env. When a Moonlight client is connected (something
 * captures the Sunshine sink monitor), the alarm plays ONLY on the Sunshine sink.
 */

const SOUND = '/usr/share/sounds/freedesktop/stereo/alarm-clock-elapsed.oga';

const FONT = [
    ['###', '# #', '# #', '# #', '###'],
    ['  #', '  #', '  #', '  #', '  #'],
    ['###', '  #', '###', '#  ', '###'],
    ['###', '  #', '###', '  #', '###'],
    ['# #', '# #', '###', '  #', '  #'],
    ['###', '#  ', '###', '  #', '###'],
    ['###', '#  ', '###', '# #', '###'],
    ['###', '  #', '  #', '  #', '  #'],
    ['###', '# #', '###', '# #', '###'],
    ['###', '# #', '###', '  #', '###'],
];
const COLON_PATTERN = [' ', '#', ' ', '#', ' '];

$stateFile = dirname(__DIR__) . '/var/state';

$tz = getenv('TZ') ?: (is_readable('/etc/timezone') ? trim((string) file_get_contents('/etc/timezone')) : '');
date_default_timezone_set($tz !== '' ? $tz : 'UTC');

pcntl_async_signals(true);

function out(string $s): void
{
    fwrite(STDOUT, $s);
}

function usageAndExit(): never
{
    fwrite(STDERR, <<<EOF
    usage: make timer <duration> [message]   duration: 1:30:00 | 25:00 | 90s | 25m | 1h30m | 300
           make timer continue               resume saved timer
           make timer cancel                 forget saved timer

    display: auto — big digits + progress bar on a terminal >= 64 cols wide,
             plain one-line countdown otherwise
             override: TIMER_MODE=plain|fancy (in .env or environment),
                       or --plain / --fancy via the bin/timer wrapper

    EOF);
    exit(1);
}

function parseDuration(string $dur): ?int
{
    if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $dur, $m)) {
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }
    if (preg_match('/^(\d+):(\d{1,2})$/', $dur, $m)) {
        return (int) $m[1] * 60 + (int) $m[2];
    }
    if (preg_match('/^\d+$/', $dur)) {
        return (int) $dur;
    }
    $total = 0;
    $rest = $dur;
    while (preg_match('/^(\d+)([hms])(.*)$/', $rest, $m)) {
        $total += (int) $m[1] * match ($m[2]) { 'h' => 3600, 'm' => 60, 's' => 1 };
        $rest = $m[3];
    }
    return $rest === '' && $total > 0 ? $total : null;
}

function hms(int $s): string
{
    return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}

function endTime(int $end): string
{
    return date('H:i:s', $end);
}

function saveState(string $file, int $left, string $msg): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    file_put_contents($file, "$left\n$msg\n");
}

function isTty(): bool
{
    return stream_isatty(STDOUT);
}

function termCols(): int
{
    $size = @exec('stty size 2>/dev/null');
    if (is_string($size) && preg_match('/^\d+ (\d+)$/', trim($size), $m)) {
        return (int) $m[1];
    }
    return (int) (getenv('COLUMNS') ?: 0);
}

function drawFrame(int $left, int $startLeft, int $end, string $msg, bool &$drawn): void
{
    $t = $startLeft >= 3600
        ? sprintf('%d:%02d:%02d', intdiv($left, 3600), intdiv($left % 3600, 60), $left % 60)
        : sprintf('%02d:%02d', intdiv($left, 60), $left % 60);
    $frac = intdiv($left * 100, $startLeft);
    $color = match (true) {
        $left <= 10 => "\e[1;31m",
        $frac > 50  => "\e[32m",
        $frac > 20  => "\e[33m",
        default     => "\e[31m",
    };
    if ($drawn) {
        out("\e[7A");
    }
    for ($row = 0; $row < 5; $row++) {
        $line = '';
        foreach (str_split($t) as $ch) {
            $line .= ($ch === ':' ? COLON_PATTERN[$row] : FONT[(int) $ch][$row]) . ' ';
        }
        $line = str_replace('#', '##', $line);
        $line = str_replace(' ', '  ', $line);
        $line = str_replace('#', '█', $line);
        out("  {$color}{$line}\e[0m\e[K\n");
    }
    $width = 40;
    $fill = intdiv($frac * $width, 100);
    $bar = str_repeat('█', $fill) . str_repeat('░', $width - $fill);
    out(sprintf("  %s%s\e[0m %3d%%\e[K\n", $color, $bar, $frac));
    out('  ends at ' . endTime($end) . " — {$msg}\e[K\n");
    $drawn = true;
}

/**
 * Telegram message at the alarm — only when .env carries a bot token and chat id.
 * A dead network must never hold the alarm back, so the request is tightly bounded
 * and a failure is reported rather than retried.
 */
function notifyTelegram(string $msg, string $finishedAt): void
{
    $token = (string) getenv('TELEGRAM_BOT_TOKEN');
    $chatId = (string) getenv('TELEGRAM_CHAT_ID');
    if ($token === '' || $chatId === '') {
        return;
    }
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => "⏰ Timer finished at {$finishedAt}\n{$msg}",
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = (string) curl_exec($ch);
    $error = curl_error($ch);
    if (!str_contains($body, '"ok":true')) {
        $reason = $error !== '' ? $error : trim($body);
        fwrite(STDERR, "timer: telegram notification failed — {$reason}\n");
    }
}

/**
 * The Sunshine sink name, but only while a Moonlight client is connected —
 * i.e. something (Sunshine) is capturing the sink's monitor source.
 */
function sunshineSinkIfStreaming(): ?string
{
    exec('pactl list short sinks 2>/dev/null', $sinks, $rc);
    if ($rc !== 0) {
        return null;
    }
    exec('pactl list short sources 2>/dev/null', $sources);
    $monitorIdx = null;
    foreach ($sources as $row) {
        $f = explode("\t", $row);
        if (isset($f[1]) && preg_match('/sunshine.*\.monitor$/i', $f[1])) {
            $monitorIdx = $f[0];
            break;
        }
    }
    if ($monitorIdx === null) {
        return null;
    }
    exec('pactl list short source-outputs 2>/dev/null', $outputs);
    $capturing = false;
    foreach ($outputs as $row) {
        $f = explode("\t", $row);
        if (($f[1] ?? '') === $monitorIdx) {
            $capturing = true;
            break;
        }
    }
    if (!$capturing) {
        return null;
    }
    foreach ($sinks as $row) {
        $f = explode("\t", $row);
        if (isset($f[1]) && stripos($f[1], 'sunshine') !== false) {
            return $f[1];
        }
    }
    return null;
}

// ---------------------------------------------------------------- arguments

array_shift($argv);
$forceMode = '';
$args = [];
foreach ($argv as $a) {
    match ($a) {
        '--plain', '-p' => $forceMode = 'plain',
        '--fancy', '-f' => $forceMode = 'fancy',
        default => $args[] = $a,
    };
}
if ($args === [] || $args[0] === '-h' || $args[0] === '--help') {
    usageAndExit();
}

$cmd = $args[0];

if ($cmd === 'cancel') {
    if (is_file($stateFile)) {
        $left = (int) trim((string) (file($stateFile, FILE_IGNORE_NEW_LINES)[0] ?? '0'));
        unlink($stateFile);
        echo 'timer: cancelled at ' . endTime(time()) . ' with ' . hms(max(0, $left)) . " left\n";
    } else {
        echo "timer: no saved timer\n";
    }
    exit(0);
}

if ($cmd === 'continue' || $cmd === 'c') {
    if (!is_file($stateFile)) {
        fwrite(STDERR, "timer: nothing to continue\n");
        exit(1);
    }
    $lines = file($stateFile, FILE_IGNORE_NEW_LINES);
    $left = (int) trim($lines[0] ?? '0');
    $msg = $lines[1] ?? 'Time is up!';
    if ($left > 0) {
        // the state always holds the remaining time — resume from exactly there
        $end = time() + $left;
        echo '⏱  Resumed at ' . endTime(time()) . ' with ' . hms($left) . ' left — ends at ' . endTime($end) . "  ($msg)\n";
    } else {
        // it stopped during the ring: fire the alarm right away
        $end = time();
        echo '⏰ Timer had already finished — ringing now at ' . endTime(time()) . "  ($msg)\n";
    }
} else {
    $total = parseDuration($cmd);
    if ($total === null) {
        usageAndExit();
    }
    $msg = count($args) > 1 ? implode(' ', array_slice($args, 1)) : 'Time is up!';
    $end = time() + $total;
    saveState($stateFile, $total, $msg);
    echo "⏱  Timer: {$cmd} — started at " . endTime(time()) . ' — ends at ' . endTime($end) . "  ($msg)\n";
}

// ---------------------------------------------------------------- countdown

$startLeft = max(1, $end - time());

$mode = $forceMode !== '' ? $forceMode : (string) getenv('TIMER_MODE');
$fancy = match ($mode) {
    'fancy' => true,
    'plain' => false,
    default => isTty() && termCols() >= 64,
};
if ($fancy) {
    out("\e[?25l"); // hide cursor
}
$cleanupTty = function () use ($fancy): void {
    if ($fancy) {
        out("\e[?25h\e[0m");
    }
};

// Ctrl+C / TERM during the countdown pauses: remaining time is stored, clock stops
$pause = function () use (&$end, $stateFile, $msg, $cleanupTty): never {
    $cleanupTty();
    $left = max(1, $end - time());
    saveState($stateFile, $left, $msg);
    out("\n⏸  paused at " . endTime(time()) . ' with ' . hms($left) . " left — resume: make timer continue | forget: make timer cancel\n");
    exit(130);
};
pcntl_signal(SIGINT, $pause);
pcntl_signal(SIGTERM, $pause);

// detect the terminal disappearing (closed window): stdin EOF on a tty means
// nobody is watching — pause instead of counting on as an orphan
$stdinIsTty = stream_isatty(STDIN);
stream_set_blocking(STDIN, false);
$stdinHungUp = function () use ($stdinIsTty): bool {
    if (!$stdinIsTty) {
        return false;
    }
    $r = [STDIN];
    $w = $e = [];
    if (@stream_select($r, $w, $e, 0, 0) > 0) {
        fread(STDIN, 4096); // drain stray input; EOF leaves the stream at eof
        return feof(STDIN);
    }
    return false;
};

$drawn = false;
while (($left = $end - time()) > 0) {
    // persist the remaining time every tick, so ANY kind of death (crash, reboot,
    // kill -9) loses at most one second and `continue` resumes from right here
    saveState($stateFile, $left, $msg);
    if ($fancy) {
        drawFrame($left, $startLeft, $end, $msg, $drawn);
    } else {
        out("\r  " . hms($left) . ' remaining ');
    }
    if ($stdinHungUp()) {
        $pause();
    }
    sleep(1);
}
$cleanupTty();
out("\r\e[K");

// ---------------------------------------------------------------- alarm

// remember the notification id so it can be closed together with the alarm
$notifyId = 0;
$idOut = @exec(
    'notify-send -p -u critical ' . escapeshellarg('⏰ Timer finished') . ' ' . escapeshellarg($msg) . ' 2>/dev/null'
);
if (is_string($idOut) && ctype_digit(trim($idOut))) {
    $notifyId = (int) trim($idOut);
}

$finishedAt = endTime(time());
notifyTelegram($msg, $finishedAt);

if ($fancy) {
    out("\x07\n  \e[1;31m⏰  T I M E   I S   U P  ⏰\e[0m\n  \e[1m{$msg}\e[0m\n  finished at {$finishedAt}\n\n");
} else {
    out("⏰ {$msg} — finished at {$finishedAt}\n");
}
out('   Press Enter to stop the alarm... ');

$dismiss = false;
$requestDismiss = function () use (&$dismiss): void {
    $dismiss = true;
};
pcntl_signal(SIGINT, $requestDismiss);
pcntl_signal(SIGTERM, $requestDismiss);

$deadline = isTty() ? null : time() + 30; // no terminal: ring 30 s, then stop

while (!$dismiss && ($deadline === null || time() < $deadline)) {
    $sink = sunshineSinkIfStreaming();
    $playCmd = $sink !== null ? ['paplay', '-d', $sink, SOUND] : ['paplay', SOUND];
    $proc = @proc_open($playCmd, [2 => ['file', '/dev/null', 'w']], $pipes);
    if ($proc === false) {
        break;
    }
    while (!$dismiss && proc_get_status($proc)['running']) {
        $r = [STDIN];
        $w = $e = [];
        $n = @stream_select($r, $w, $e, 0, 200000);
        if ($n > 0) {
            if (fgets(STDIN) !== false) {
                $dismiss = true;
            } elseif (feof(STDIN)) {
                // terminal went away mid-ring: don't ring forever to an empty room
                $deadline ??= time() + 30;
                usleep(100_000);
            }
        }
        if ($deadline !== null && time() >= $deadline) {
            break;
        }
    }
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc);
    }
    proc_close($proc);
    if (!$dismiss && ($deadline === null || time() < $deadline)) {
        usleep(300_000);
    }
}

// the desktop notification must not outlive the alarm
if ($notifyId > 0) {
    @exec(
        'gdbus call --session --dest org.freedesktop.Notifications'
        . ' --object-path /org/freedesktop/Notifications'
        . ' --method org.freedesktop.Notifications.CloseNotification ' . $notifyId . ' >/dev/null 2>&1'
    );
}
@unlink($stateFile);
out("\n");
