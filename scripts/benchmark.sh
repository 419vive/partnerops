#!/usr/bin/env sh
set -eu

base_url="${1:-http://localhost:8080}"
base_url="${base_url%/}"
samples="${BENCH_SAMPLES:-30}"
threshold_ms="${BENCH_THRESHOLD_MS:-750}"
login_path="${BENCH_LOGIN_PATH:-/login}"
login_success_path="${BENCH_LOGIN_SUCCESS_PATH:-/}"
dashboard_path="${BENCH_DASHBOARD_PATH:-/}"
queue_path="${BENCH_QUEUE_PATH:-/requests?status=in_progress&page=1}"
email="${BENCH_EMAIL:-agent@partnerops.test}"
password="${BENCH_PASSWORD:-PartnerOps!2026}"
username_field="${BENCH_USERNAME_FIELD:-_username}"
password_field="${BENCH_PASSWORD_FIELD:-_password}"

case "$samples" in
    ''|*[!0-9]*|0) echo "BENCH_SAMPLES must be a positive integer" >&2; exit 2 ;;
esac
case "$threshold_ms" in
    ''|*[!0-9]*|0) echo "BENCH_THRESHOLD_MS must be a positive integer" >&2; exit 2 ;;
esac
case "$login_success_path" in
    //*|'') echo "BENCH_LOGIN_SUCCESS_PATH must be a local absolute path" >&2; exit 2 ;;
    /*) ;;
    *) echo "BENCH_LOGIN_SUCCESS_PATH must be a local absolute path" >&2; exit 2 ;;
esac

for command in curl awk sed sort mktemp uname; do
    command -v "$command" >/dev/null 2>&1 || {
        echo "Missing required command: $command" >&2
        exit 2
    }
done

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT HUP INT TERM
cookie_jar="$work_dir/cookies.txt"
login_html="$work_dir/login.html"
login_headers="$work_dir/login-headers.txt"
login_result_html="$work_dir/login-result.html"

curl --fail --silent --show-error --max-time 10 \
    --cookie-jar "$cookie_jar" \
    "$base_url$login_path" >"$login_html"

csrf_token="$(sed -n 's/.*name="_csrf_token"[^>]*value="\([^"]*\)".*/\1/p' "$login_html" | sed -n '1p')"
if [ -z "$csrf_token" ]; then
    echo "Could not find the login CSRF token at $base_url$login_path" >&2
    exit 1
fi

session_cookie_before="$(awk '$6 == "partnerops_session" { print $7; exit }' "$cookie_jar")"

safe_local_path() {
    raw_value="$1"
    case "$raw_value" in
        '')
            printf '%s\n' 'none'
            ;;
        "$base_url")
            printf '%s\n' '/'
            ;;
        "$base_url"/*)
            local_path="${raw_value#"$base_url"}"
            local_path="${local_path%%\?*}"
            local_path="${local_path%%#*}"
            printf '%s\n' "${local_path:-/}"
            ;;
        //*)
            printf '%s\n' '[external]'
            ;;
        /*)
            local_path="${raw_value%%\?*}"
            local_path="${local_path%%#*}"
            printf '%s\n' "${local_path:-/}"
            ;;
        *)
            printf '%s\n' '[external]'
            ;;
    esac
}

# Curl does not synthesize browser Fetch Metadata; the stateless CSRF manager
# therefore needs explicit same-origin evidence for the login form POST.
set +e
login_result="$(curl --silent --show-error --location --max-time 10 \
    --cookie "$cookie_jar" \
    --cookie-jar "$cookie_jar" \
    --data-urlencode "$username_field=$email" \
    --data-urlencode "$password_field=$password" \
    --data-urlencode "_csrf_token=$csrf_token" \
    --referer "$base_url$login_path" \
    --dump-header "$login_headers" \
    --output "$login_result_html" \
    --write-out '%{http_code}\n%{url_effective}' \
    "$base_url$login_path")"
login_curl_status=$?
set -e
if [ "$login_curl_status" -ne 0 ]; then
    printf 'login_transport_exit=%s\n' "$login_curl_status" >&2
    exit 1
fi
login_post_status="$(awk '/^HTTP\// { print $2; exit }' "$login_headers")"
login_location_raw="$(awk 'tolower($1) == "location:" { sub(/^[^:]*:[[:space:]]*/, ""); sub(/\r$/, ""); print; exit }' "$login_headers")"
login_location="$(safe_local_path "$login_location_raw")"
login_final_status="$(printf '%s\n' "$login_result" | sed -n '1p')"
effective_url="$(printf '%s\n' "$login_result" | sed -n '2p')"
effective_path="$(safe_local_path "$effective_url")"
session_cookie_after="$(awk '$6 == "partnerops_session" { print $7; exit }' "$cookie_jar")"

session_cookie_initial=absent
[ -n "$session_cookie_before" ] && session_cookie_initial=present
session_cookie_final=absent
[ -n "$session_cookie_after" ] && session_cookie_final=present
session_cookie_rotated=false
[ -n "$session_cookie_before" ] && [ -n "$session_cookie_after" ] \
    && [ "$session_cookie_before" != "$session_cookie_after" ] && session_cookie_rotated=true
auth_error_present=false
if awk 'index($0, "flash--error") && index($0, "role=\"alert\"") { found = 1 } END { exit found ? 0 : 1 }' "$login_result_html"; then
    auth_error_present=true
fi

printf 'login_post_status=%s login_location=%s login_final_status=%s login_final_path=%s auth_error_present=%s session_cookie_initial=%s session_cookie_final=%s session_cookie_rotated=%s\n' \
    "${login_post_status:-unknown}" "${login_location:-none}" "${login_final_status:-unknown}" \
    "${effective_path:-unknown}" "$auth_error_present" "$session_cookie_initial" \
    "$session_cookie_final" "$session_cookie_rotated"

if [ "$login_post_status" != "302" ]; then
    echo "Login POST returned unexpected HTTP ${login_post_status:-unknown}" >&2
    exit 1
fi
if [ "$login_location" != "$login_success_path" ]; then
    echo "Login POST did not redirect to $login_success_path; verify BENCH_* credentials and request headers" >&2
    exit 1
fi
if [ "$effective_path" != "$login_success_path" ]; then
    echo "Login did not finish at $login_success_path; verify authenticated session handling" >&2
    exit 1
fi

measure() {
    label="$1"
    path="$2"
    output="$work_dir/$label.txt"

    warmup=1
    while [ "$warmup" -le 3 ]; do
        curl --fail --silent --show-error --max-time 10 \
            --cookie "$cookie_jar" --output /dev/null "$base_url$path"
        warmup=$((warmup + 1))
    done

    count=1
    while [ "$count" -le "$samples" ]; do
        seconds="$(curl --fail --silent --show-error --max-time 10 \
            --cookie "$cookie_jar" \
            --header 'Cache-Control: no-cache' \
            --output /dev/null \
            --write-out '%{time_starttransfer}' \
            "$base_url$path")"
        awk -v value="$seconds" 'BEGIN { printf "%.0f\n", value * 1000 }' >>"$output"
        count=$((count + 1))
    done

    sort -n "$output" -o "$output.sorted"
    p50_index=$(((samples + 1) / 2))
    p95_index=$(((95 * samples + 99) / 100))
    p50="$(sed -n "${p50_index}p" "$output.sorted")"
    p95="$(sed -n "${p95_index}p" "$output.sorted")"
    minimum="$(sed -n '1p' "$output.sorted")"
    maximum="$(sed -n "${samples}p" "$output.sorted")"

    printf '%-20s min=%4sms  p50=%4sms  p95=%4sms  max=%4sms\n' "$label" "$minimum" "$p50" "$p95" "$maximum"
    [ "$p95" -le "$threshold_ms" ]
}

printf 'PartnerOps HTTP benchmark\n'
printf 'UTC: %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
printf 'Host: %s | base=%s | samples=%s | p95 limit=%sms\n' "$(uname -sm)" "$base_url" "$samples" "$threshold_ms"
printf 'Queue path: %s\n' "$queue_path"
printf 'Load the disposable 10,000-request performance fixture before trusting these numbers.\n\n'

failed=0
measure dashboard "$dashboard_path" || failed=1
measure filtered_queue "$queue_path" || failed=1

if [ "$failed" -ne 0 ]; then
    echo "Benchmark failed: at least one p95 exceeded ${threshold_ms}ms." >&2
    exit 1
fi

echo "Benchmark passed."
