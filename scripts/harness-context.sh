#!/usr/bin/env bash
# SessionStart Hook 用。読み取り専用で、前回の状態を短く出力する。
cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/..}" || exit 0

echo "## Harness context"
if git rev-parse --git-dir >/dev/null 2>&1; then
  echo "branch: $(git branch --show-current 2>/dev/null)"
  echo "last commits:"; git log --oneline -3 2>/dev/null | sed 's/^/  /' || echo "  (なし)"
  changes="$(git status --short 2>/dev/null)"
  if [ -n "$changes" ]; then
    echo "WARNING: 未コミットの変更がある。作業前に開発者へ報告すること（reset しない）:"
    echo "$changes" | head -20 | sed 's/^/  /'
  fi
else
  echo "WARNING: git リポジトリではない"
fi

if [ -f .harness/feature_list.json ] && command -v jq >/dev/null; then
  echo "tasks (done/all): $(jq '[.features[]|select(.status=="done")]|length' .harness/feature_list.json)/$(jq '.features|length' .harness/feature_list.json)"
  echo "next pending: $(jq -r '[.features[]|select(.status!="done")][0] | "\(.id) \(.name)"' .harness/feature_list.json)"
fi

if [ -f .harness/progress.md ]; then
  awk '/^## (Current task|Issues|Next action)/{p=1; print; next} /^## /{p=0} p && NF' .harness/progress.md
fi
[ -f .harness/evidence/last_check.txt ] && { echo "last check:"; sed 's/^/  /' .harness/evidence/last_check.txt; }
exit 0
