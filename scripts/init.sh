#!/usr/bin/env bash
# 環境確認。何も変更しない。秘密値は表示しない。
set -uo pipefail
cd "$(dirname "$0")/.."

ok=0
check() { # check <名前> <コマンド...>
  local name="$1"; shift
  if out="$("$@" 2>&1 | head -1)" && [ -n "$out" ]; then
    printf "  [OK]   %-10s %s\n" "$name" "$out"
  else
    printf "  [NG]   %-10s %s\n" "$name" "${out:-見つからない}"
    ok=1
  fi
}

export PATH="$HOME/.orbstack/bin:$PATH"   # OrbStack の docker CLI

echo "== ツール（PHP / Composer はコンテナ内で使う） =="
check docker   docker version --format 'Docker {{.Server.Version}}'
check compose  docker compose version
check node     node --version
check git      git --version
check jq       jq --version
check gitleaks gitleaks version

echo "== アプリ =="
if [ -f backend/artisan ]; then
  echo "  [OK]   backend/artisan あり"
  if [ -f backend/.env ]; then echo "  [OK]   backend/.env あり（中身は表示しない）"
  else echo "  [NG]   backend/.env なし（.env.example からコピーが必要）"; ok=1; fi
  [ -d backend/vendor ] && echo "  [OK]   backend/vendor あり" || { echo "  [NG]   backend/vendor なし（composer install が必要・要承認）"; ok=1; }
  for svc in app db; do
    if docker compose ps --status running --services 2>/dev/null | grep -qx "$svc"; then
      echo "  [OK]   コンテナ $svc 起動中"
    else
      echo "  [NG]   コンテナ $svc 停止中（docker compose up -d）"; ok=1
    fi
  done
  check php-app  docker compose exec -T app php -r 'echo "PHP ".PHP_VERSION;'
else
  echo "  [--]   backend/ 未作成（RS-001）"
fi

echo "== ハーネス =="
jq empty .harness/feature_list.json 2>/dev/null \
  && echo "  [OK]   feature_list.json は正しい JSON" \
  || { echo "  [NG]   feature_list.json が壊れている"; ok=1; }

[ $ok -eq 0 ] && echo "INIT: OK" || echo "INIT: NG（上の [NG] を解消すること）"
exit $ok
