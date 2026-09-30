#!/usr/bin/env bash
#
# ScamSense — how many submissions were monitoring probes, per day.
#
# Run on the VM:   sudo bash /var/www/scamsense/deploy/monitoring/count.sh
#
# Probes are identified by content_hash: SHA-256 of the trimmed text
# (Submission::hashContent). The probe texts contain nothing the redactor
# changes, so the hash of the text in probes.sh is the stored hash.
# Quote the "real" column, never the total, as usage.

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"

hashes="$(grep -oE '^  "[0-9]+\|[a-z]+\|[^|]+\|.*"$' "$HERE/probes.sh" |
    sed -E 's/^  "[0-9]+\|[a-z]+\|[^|]+\|(.*)"$/\1/' |
    while IFS= read -r text; do
        printf "'%s'," "$(printf '%s' "$text" | sha256sum | cut -d' ' -f1)"
    done)"
hashes="${hashes%,}"

mysql scamsense -t <<SQL
SELECT DATE(created_at) AS day,
       COUNT(*) AS total,
       SUM(content_hash IN ($hashes)) AS probes,
       SUM(content_hash NOT IN ($hashes)) AS \`real\`
FROM submissions
GROUP BY DATE(created_at)
ORDER BY day;
SQL
