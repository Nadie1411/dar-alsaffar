#!/usr/bin/env bash
# Small helpers sourced by scripts/deploy.sh. Kept apart so they can be tested on their own.

# True when $1 is a backend the application knows how to run on.
valid_backend() {
    case "${1:-}" in
        local | overzaki) return 0 ;;
        *) return 1 ;;
    esac
}

# set_env_value FILE KEY VALUE — sets KEY=VALUE in an .env file, replacing the
# line if there is one and appending it if not. The value travels through the
# environment, never through a command line or an awk program, so characters
# like & \ or / in it are written exactly as given. The file is rewritten in
# place (not replaced), which keeps it bind-mount friendly and keeps its owner.
set_env_value() {
    local file="$1" key="$2" value="$3" tmp
    [ -f "$file" ] || : > "$file"
    tmp="$(mktemp)"
    ENV_KEY="$key" ENV_VALUE="$value" awk '
        BEGIN { key = ENVIRON["ENV_KEY"]; value = ENVIRON["ENV_VALUE"]; done = 0 }
        index($0, key "=") == 1 { if (!done) { print key "=" value; done = 1 }; next }
        { print }
        END { if (!done) print key "=" value }
    ' "$file" > "$tmp"
    cat "$tmp" > "$file"
    rm -f "$tmp"
}
