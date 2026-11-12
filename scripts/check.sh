#!/usr/bin/env bash
# 品質ゲート。最後に "GATE: PASS" が出たときだけ合格。
#   exit 0 = PASS / 1 = FAIL / 2 = NOT CONFIGURED
# このスクリプトを緩める変更は開発者の承認が必要。
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
EVIDENCE="$ROOT/.harness/evidence"
mkdir -p "$EVIDENCE"

results=()
failed=0
step() { # step <名前> <コマンド...>
  local name="$1"; shift
  echo "---- $name"
  if "$@"; then results+=("PASS  $name"); else results+=("FAIL  $name"); failed=1; fi
}

finish() {
  local gate="$1"
  {
    echo "date: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "git:  $(git rev-parse --short HEAD 2>/dev/null || echo 'no-commit')$(git diff --quiet 2>/dev/null || echo ' (+uncommitted)')"
    printf '%s\n' "${results[@]}"
    echo "GATE: $gate"
  } > "$EVIDENCE/last_check.txt"
  echo "==== 結果"
  printf '  %s\n' "${results[@]}"
  echo "GATE: $gate"
}

# 1. ハーネスの整合性（passes=true には done と evidence が必要）
harness_check() {
  jq -e '
    [.features[]
      | select(.status | IN("pending","in_progress","blocked","done") | not)
    ] | length == 0' .harness/feature_list.json >/dev/null \
    || { echo "不正な status がある"; return 1; }
  local bad
  bad=$(jq -r '.features[] | select(.passes == true and (.status != "done" or (.evidence | length) == 0)) | .id' .harness/feature_list.json)
  [ -z "$bad" ] || { echo "根拠なしで passes=true: $bad"; return 1; }
}
step "harness: feature_list.json" harness_check

# 2. 秘密情報（gitleaks が無ければ FAIL。未実行を PASS にしない）
secrets_check() {
  command -v gitleaks >/dev/null || { echo "gitleaks が未インストール"; return 1; }
  gitleaks dir . --no-banner --redact -v --max-target-megabytes 5 2>&1 | tail -5
  return "${PIPESTATUS[0]}"
}
step "security: gitleaks" secrets_check

# 3. アプリ（未作成なら NOT CONFIGURED で止める）
if [ ! -f backend/artisan ]; then
  results+=("SKIP  app: backend/ 未作成（RS-001）")
  if [ $failed -ne 0 ]; then finish "FAIL"; exit 1; fi
  finish "NOT CONFIGURED"; exit 2
fi

# PHP は app コンテナで実行する（ローカル PHP は使わない）。コンテナが動いていなければ FAIL
export PATH="$HOME/.orbstack/bin:$PATH"
if ! docker compose ps --status running --services 2>/dev/null | grep -qx app; then
  results+=("FAIL  app: app コンテナが起動していない（docker compose up -d）")
  finish "FAIL"; exit 1
fi
app() { docker compose exec -T app "$@"; }
step "app: php artisan test" app php artisan test
step "app: pint --test"      app ./vendor/bin/pint --test
if [ -x backend/vendor/bin/phpstan ]; then
  step "app: phpstan" app ./vendor/bin/phpstan analyse --no-progress
fi
cd backend
if [ -f package.json ] && grep -q '"build"' package.json; then
  step "app: npm run build" npm run build --silent
fi
# テストの skip を合格扱いにしない
step "app: no skipped tests" bash -c '! grep -rnE "markTestSkipped|->skip\(|markTestIncomplete" tests/'
cd "$ROOT"

if [ $failed -eq 0 ]; then finish "PASS"; exit 0; fi
finish "FAIL"; exit 1
