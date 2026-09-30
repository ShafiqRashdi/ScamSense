#!/usr/bin/env bash
#
# ScamSense — synthetic monitoring probes against the live site.
#
#     bash probes.sh A        # probes 1-5
#     bash probes.sh B        # probes 6-10
#     bash probes.sh all
#
# Each probe is submitted exactly as a visitor would (fetch the form for its
# CSRF token, POST /verify, follow to /result), and the verdict label on the
# result page is compared with the expected one. Then the BM switch is checked
# on the last result. Exit status 0 only if everything matched.
#
# THESE ARE NOT VISITORS. Every run adds real rows to `submissions`, so the
# admin dashboard's volume includes them. The texts are fixed, which makes
# them exactly countable by content_hash; see docs/monitoring-probes.md before
# quoting any submission count. Probes never press a feedback button, so
# nothing reaches the moderation queue or the retraining set.

set -uo pipefail

SITE="${SITE:-https://scamsense.malaysiawest.cloudapp.azure.com}"
SET="${1:-all}"

# id | type | expected label (English) | content
#
# "Expected" is what the live system answered on 2026-09-30, not the truth:
# the probes detect CHANGE. Four answers are wrong or weak today (4, 8, 10
# are ordinary messages rated "Not certain"; 9 is a scam rated "Not certain").
# See docs/monitoring-probes.md. Do not edit these to make a run pass.
PROBES=(
  "1|url|Likely a scam|https://maybank2u.com.my.secure-verify.xyz/login"
  "2|url|Verified address|https://www.maybank2u.com.my/home/m2u/common/login.do"
  "3|message|Likely a scam|Pos Laju: Bungkusan anda tidak dapat dihantar kerana alamat tidak lengkap. Sila kemas kini dalam 24 jam di http://poslaju-my.info/track"
  "4|message|Not certain|Mak, saya balik lambat sikit malam ni, ada kelas tambahan."
  "5|url|Likely a scam|http://lhdn-refund.site/claim"
  "6|url|Verified address|https://www.hasil.gov.my/"
  "7|message|Likely a scam|Tahniah! Anda memenangi RM5,000 dalam cabutan bertuah. Sila tuntut hadiah anda dalam 24 jam di http://hadiah-tuntut.xyz"
  "8|message|Not certain|Hi team, the meeting is moved to 3pm tomorrow in room B."
  "9|message|Not certain|Akaun bank anda telah dibekukan. Sila sahkan maklumat anda segera di http://bank-sahkan.online untuk mengelakkan akaun ditutup."
  "10|message|Not certain|Jangan lupa beli susu dan roti masa balik nanti ya."
)

case "$SET" in
  A) PICK="1 2 3 4 5" ;;
  B) PICK="6 7 8 9 10" ;;
  all) PICK="1 2 3 4 5 6 7 8 9 10" ;;
  *) echo "usage: probes.sh A|B|all"; exit 2 ;;
esac

LABELS='Likely a scam|Not certain|No warning signs|Could not check|Verified address'
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT
failures=0

page_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE/")"
echo "$(date -u '+%F %T') UTC  home page: HTTP $page_code"
[[ "$page_code" == "200" ]] || { echo "FAIL site is not answering"; exit 1; }

for id in $PICK; do
    for entry in "${PROBES[@]}"; do
        [[ "${entry%%|*}" == "$id" ]] || continue
        IFS='|' read -r _ type expected content <<<"$entry"

        # Fresh visitor per probe: English, own session, own CSRF token.
        : > "$JAR"
        token="$(curl -s -c "$JAR" -b "$JAR" --max-time 20 "$SITE/" |
            grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')"
        start=$(date +%s%N)
        curl -s -c "$JAR" -b "$JAR" -o /dev/null --max-time 30 \
            --data-urlencode "_token=$token" --data-urlencode "type=$type" \
            --data-urlencode "content=$content" "$SITE/verify"
        html="$(curl -s -c "$JAR" -b "$JAR" --max-time 20 "$SITE/result")"
        ms=$(( ($(date +%s%N) - start) / 1000000 ))
        # The verified panel outranks the band label, which also appears
        # first in <title>; otherwise take the first label on the page.
        if grep -q 'Verified address' <<<"$html"; then
            got="Verified address"
        else
            got="$(grep -oE "$LABELS" <<<"$html" | head -1)"
        fi

        if [[ "$got" == "$expected" ]]; then
            printf 'PASS  #%-2s %-7s %-17s %5d ms\n' "$id" "$type" "$got" "$ms"
        else
            printf 'FAIL  #%-2s %-7s expected "%s", got "%s" (%d ms)\n' "$id" "$type" "$expected" "${got:-nothing}" "$ms"
            failures=$((failures + 1))
        fi
    done
done

# The language switch returns to the result and renders it in Malay.
switch="$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{redirect_url}' --max-time 20 \
    "$SITE/locale/ms?from=verify.result")"
# Read the whole page before testing it: grep -q stops reading early, and
# under pipefail the interrupted curl turns a pass into a failure.
switched="$(curl -s -c "$JAR" -b "$JAR" --max-time 20 "$SITE/result")"
if [[ "$switch" == "$SITE/result" ]] && grep -q 'lang="ms"' <<<"$switched"; then
    echo "PASS  BM switch stays on the result"
else
    echo "FAIL  BM switch went to: ${switch:-nowhere}"
    failures=$((failures + 1))
fi

echo "$failures failure(s)"
exit $(( failures > 0 ))
