# timer

Terminal countdown timer with a real audible alarm that keeps ringing until you
dismiss it. Runs in Docker (PHP 8.5), so nothing is installed on the host.

Features:

- Big-digit countdown with a color progress bar (auto-falls back to a plain
  one-liner in narrow terminals, pipes, scripts)
- Repeating alarm sound — impossible to miss, stops on Enter
- Desktop notification that is closed automatically when you stop the alarm
- Stopping it in any way — Ctrl+C, closed terminal, crash, reboot — just stops
  the clock; `continue` resumes from exactly where it stopped

## Requirements

- Linux with PipeWire or PulseAudio (audio and notifications are wired to the
  host's session sockets)
- Docker with the compose plugin
- `make`

macOS: the countdown works anywhere Docker does, but the alarm sound and
notifications rely on Linux desktop plumbing (Pulse socket, DBus). On macOS you
will get a silent timer.

Windows: Can try to use WSL.

## Installation

```bash
git clone git@github.com:EugeneGpil/timer.git
cd timer
```

Then can already use from current directory:

```bash
make timer 25:00 tea is ready
```

### Make it accessible from everywhere (optional)

The repo ships a wrapper at `bin/timer` that finds the repo on its own, so a
symlink from any `PATH` directory is enough.

Linux:

```bash
ln -s "$(pwd)/bin/timer" ~/.local/bin/timer     # ~/.local/bin is usually in PATH
```

macOS (note the audio limitation above):

```bash
ln -s "$(pwd)/bin/timer" /usr/local/bin/timer
```

Then from any directory:

```bash
timer 25:00 tea is ready
```

The wrapper also accepts display flags that `make` cannot take:

```bash
timer --plain 25:00
timer --fancy continue
```

## Usage

```bash
timer <duration> [message...]
timer continue
timer cancel
```

Examples:

```bash
timer 45:00                  # 45 minutes
timer 1:30:00 pizza          # 1 h 30 m, message "pizza"
timer 90s                    # 90 seconds
timer 1h30m coffee break     # suffix format works too
timer 300                    # plain seconds
```

While the countdown runs:

- **Ctrl+C — pause.** The remaining time is saved and the clock stops.
- Closing the terminal, a crash, or a reboot pauses it too: the remaining time
  is written to disk every second, so at most one second is lost.

When the alarm rings:

- **Enter** (or Ctrl+C) — stop the alarm. The desktop notification is closed
  automatically with it.
- Without a terminal attached, the alarm stops itself after 30 seconds.

### Continue / cancel

```bash
timer continue
timer cancel      # forget the saved timer
```

However the timer stopped — Ctrl+C, closed terminal, crash, reboot — `continue`
resumes it with exactly the remaining time it had. Time spent stopped never
counts. (If it stopped while the alarm was already ringing, `continue` rings
immediately.)

Only one timer is stored: starting a new one overwrites the saved state.

## Time formats

| Format | Example | Meaning |
|---|---|---|
| `H:M:S` | `1:30:00` | 1 hour 30 minutes |
| `M:S` | `45:00` | 45 minutes |
| suffixes | `90s`, `25m`, `1h30m` | combinable |
| plain number | `300` | seconds |

## Configuration (.env)

Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

| Variable | Values | Default | Meaning |
|---|---|---|---|
| `TIMER_MODE` | `plain` / `fancy` / empty | empty (auto) | Force the display mode. Auto picks fancy (big digits + progress bar) on a terminal at least 64 columns wide, plain otherwise. |
| `UID` | your `id -u` | `1000` | Host user the container runs as — must match your desktop session, or audio and notifications are rejected. |
| `GID` | your `id -g` | `1000` | Host group the container runs as. |

Precedence: `--plain`/`--fancy` wrapper flags > `TIMER_MODE` in the shell
environment > `.env` > auto-detect.
