#!/usr/bin/env bash
# milestone_cameras_read.sh
# -------------------------
# Reader that Zabbix's external check invokes for the cameras master item.
# It cats the JSON file produced by milestone_cameras_refresh.sh, but FIRST
# reduces each camera record to the fields the dashboard actually reads.
#
# Why slim: the full snapshot keeps the entire Milestone camera object per
# camera (createdDate, gisPoint, coverage*, prebuffer*, edge-storage flags,
# relations, …). At ~2,650 cameras that's ~6 MB. The dashboard only consumes
# address / mac / hardware name+model / displayName / enabled (status comes
# from the ESS calc, not here), so we keep just those. Slimming drops it to
# under ~2 MB and keeps both the __array (for LLD) and the per-GUID lookup
# (for the milestone.cam.<field>[<id>] dependent items' JSONPath).
#
# NOTE: this only keeps fields the dashboard reads. Any milestone.cam.* item
# that extracts a field NOT in KEEP below will go blank — add the field to
# KEEP if you template a new per-camera item.
#
# Usage from Zabbix item key:
#   milestone_cameras_read.sh[]
#   milestone_cameras_read.sh[3600]    # custom max age
#
# Output file (must match milestone_cameras_refresh.sh):
#   /var/lib/zabbix/milestone_cameras_state.json

set -euo pipefail

OUT_FILE="/var/lib/zabbix/milestone_cameras_state.json"
ERR_FILE="/var/lib/zabbix/milestone_cameras_state.err"

# Tolerated age in seconds before we consider the snapshot stale.
# Default: 1 hour (4x the recommended 15-minute refresh cadence).
MAX_AGE="${1:-3600}"

if [[ ! -f "$OUT_FILE" ]]; then
    msg="snapshot file missing at $OUT_FILE; has milestone_cameras_refresh.sh run yet?"
    printf '{"error":"no_snapshot","detail":"%s"}\n' "$msg"
    exit 0  # exit 0 so Zabbix stores the JSON, not a script-failure state
fi

# File age check.
NOW=$(date +%s)
MTIME=$(stat -c%Y "$OUT_FILE" 2>/dev/null || echo 0)
AGE=$(( NOW - MTIME ))
if [[ "$AGE" -gt "$MAX_AGE" ]]; then
    err_detail=""
    if [[ -f "$ERR_FILE" ]]; then
        err_detail=$(tr '\n' ' ' < "$ERR_FILE")
    fi
    printf '{"error":"stale","age_seconds":%d,"max_age_seconds":%d,"last_refresh_error":"%s"}\n' \
        "$AGE" "$MAX_AGE" "$err_detail"
    exit 0
fi

# Emit the snapshot reduced to the dashboard-consumed fields. Falls back to
# the raw file only if python is unavailable.
python3 - "$OUT_FILE" <<'PY' || cat "$OUT_FILE"
import json, sys

KEEP = ("id", "displayName", "name", "enabled", "address", "mac", "macRaw",
        "hardwareId", "hardwareName", "hardwareModel")

with open(sys.argv[1]) as f:
    data = json.load(f)

def slim(rec):
    return {k: rec[k] for k in KEEP if k in rec} if isinstance(rec, dict) else rec

arr = [slim(r) for r in data.get("__array", [])]
out = {
    "__count":      data.get("__count"),
    "__fetched_at": data.get("__fetched_at"),
    "__array":      arr,
}
# Rebuild the per-GUID lookup the dependent items resolve against.
for r in arr:
    rid = r.get("id")
    if rid:
        out[rid] = r

sys.stdout.write(json.dumps(out, separators=(",", ":")))
sys.stdout.write("\n")
PY
