#!/usr/bin/env bash
# Catalog enrichment pipeline (local only). See README.md.
#
#   ./enrich.sh restore <dump.sql[.gz]> [-y]   replace the LOCAL db with a prod dump (backs up the old one)
#   ./enrich.sh collapse [--apply]             LOCAL: undo old hand-made variants listed in <run>/collapse-plan.json
#   ./enrich.sh extract                        new run: data/*.jsonl, snapshot.tsv, summary.md
#   ./enrich.sh props-prepare [--all]          agent batches of new/changed products -> props/in/
#   ./enrich.sh props-validate                 check agent output -> props/review.csv
#   ./enrich.sh seo-prepare [--gsc <zip>]      agent batches for the SEO rewrite -> seo/in/
#   ./enrich.sh seo-validate                   check agent output -> seo/review.csv
#   ./enrich.sh variants-prepare               candidate clusters of near-identical products -> variants/in/
#   ./enrich.sh variants-validate              check agent families -> variants/review.csv
#   ./enrich.sh categories-prepare             products without a category -> categories/in/
#   ./enrich.sh categories-validate            check agent output -> categories/review.csv
#   ./enrich.sh reuse <old run name>            copy an earlier run's agent results into the latest run
#   ./enrich.sh review <review.csv>...         store the `decision` column in state/decisions.json
#   ./enrich.sh build                          accepted rows -> import/NN-*.csv + import/README.md
#   ./enrich.sh import-local                   LOCAL: import every file of the run in order + apply fixes-manual.json
#   ./enrich.sh verify                         after a LOCAL import: compare with the run's snapshot
#   ./enrich.sh finish                         after the PROD import: remember what was read
#
# RUN=<run name> picks a run other than the latest.
set -euo pipefail
source "$(dirname "$0")/config.sh"
S="$CE_DIR/stages"
py() { python3 -I "$S/$1" "${@:2}"; }

cmd="${1:-}"; shift || true
case "$cmd" in restore|extract|review|""|-h|--help) ;; *) R="$(current_run)" || exit 1 ;; esac
case "$cmd" in
    restore)        "$S/restore.sh" "$@" ;;
    extract)        "$S/extract.sh" ;;
    collapse)       "$S/collapse.sh" "$@" ;;
    props-prepare)  py props_prepare.py "$R" "$@" ;;
    props-validate) py props_validate.py "$R" ;;
    seo-prepare)    py seo_prepare.py "$R" "$@" ;;
    seo-validate)   py seo_validate.py "$R" ;;
    variants-prepare)  py variants_prepare.py "$R" "$@" ;;
    variants-validate) py variants_validate.py "$R" ;;
    categories-prepare)  py categories_prepare.py "$R" "$@" ;;
    categories-validate) py categories_validate.py "$R" ;;
    reuse)          py reuse.py "$RUNS_DIR/$1" "$R" ;;
    review)         py review.py "$@" ;;
    build)          py build.py "$R" ;;
    import-local)   "$S/import-local.sh" ;;
    verify)         "$S/snapshot.sh" "$R/snapshot-after.tsv"; py verify.py "$R" "$R/snapshot-after.tsv" ;;
    finish)         py finish.py "$R" ;;
    summary)        py summary.py "$R" ;;
    *)              sed -n '2,21p' "$0"; exit 1 ;;
esac
