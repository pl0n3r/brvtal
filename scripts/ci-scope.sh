#!/usr/bin/env bash

# Deterministic BRVTAL changed-file classifier.
# Source this file, then call:
#   brvtal_ci_classify_files "$changed_file_list" "$event_name"
# Results are exposed as BRVTAL_SCOPE_* shell variables.

brvtal_ci_scope_reset() {
  BRVTAL_SCOPE_FULL=false
  BRVTAL_SCOPE_RUN_PHP=false
  BRVTAL_SCOPE_RUN_JS=false
  BRVTAL_SCOPE_RUN_DB=false
  BRVTAL_SCOPE_RUN_BROWSER=false
  BRVTAL_SCOPE_RUN_REALSTACK=false
  BRVTAL_SCOPE_RUN_WEBKIT=false
  BRVTAL_SCOPE_RUN_RECOVERY=false
  BRVTAL_SCOPE_AREAS=""
}

brvtal_ci_scope_add_area() {
  local area="$1"
  case ",${BRVTAL_SCOPE_AREAS}," in
    *",${area},"*) ;;
    *) BRVTAL_SCOPE_AREAS="${BRVTAL_SCOPE_AREAS:+${BRVTAL_SCOPE_AREAS}, }${area}" ;;
  esac
}

brvtal_ci_classify_files() {
  local changed_file_list="${1:-}"
  local event_name="${2:-pull_request}"
  local file

  brvtal_ci_scope_reset

  if [[ "$event_name" == "workflow_dispatch" ]]; then
    BRVTAL_SCOPE_FULL=true
    BRVTAL_SCOPE_RUN_PHP=true
    BRVTAL_SCOPE_RUN_JS=true
    BRVTAL_SCOPE_RUN_DB=true
    BRVTAL_SCOPE_RUN_BROWSER=true
    BRVTAL_SCOPE_RUN_REALSTACK=true
    BRVTAL_SCOPE_RUN_WEBKIT=true
    BRVTAL_SCOPE_RUN_RECOVERY=true
  fi

  while IFS= read -r file; do
    [[ -z "$file" ]] && continue
    case "$file" in
      discadmin/*.js|discadmin/*.css)
        brvtal_ci_scope_add_area "DISCADMIN UI"; BRVTAL_SCOPE_RUN_BROWSER=true ;;
      discadmin/*.php)
        brvtal_ci_scope_add_area "DISCADMIN PHP"; BRVTAL_SCOPE_RUN_BROWSER=true; BRVTAL_SCOPE_RUN_REALSTACK=true ;;
      discadmin/*)
        brvtal_ci_scope_add_area "DISCADMIN"; BRVTAL_SCOPE_RUN_BROWSER=true ;;
      api/contact.php|api/public*.php|api/hero-slider.php)
        brvtal_ci_scope_add_area "Public API"; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_BROWSER=true; BRVTAL_SCOPE_RUN_REALSTACK=true ;;
      api/*)
        brvtal_ci_scope_add_area "API"; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_REALSTACK=true ;;
      database/*)
        brvtal_ci_scope_add_area "Database/migrations"; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_REALSTACK=true ;;
      config/public_*.php)
        brvtal_ci_scope_add_area "Public runtime config"; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_BROWSER=true; BRVTAL_SCOPE_RUN_REALSTACK=true ;;
      config/*)
        brvtal_ci_scope_add_area "Runtime config"; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_REALSTACK=true ;;
      js/*|css/*|index.php|index.html|sitemap.php|assets/*|uploads/*)
        brvtal_ci_scope_add_area "Public web"; BRVTAL_SCOPE_RUN_BROWSER=true ;;
      tests/integration/backup-recovery-rehearsal.php)
        brvtal_ci_scope_add_area "Recovery testing"; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_RECOVERY=true ;;
      tests/integration/*)
        brvtal_ci_scope_add_area "Integration tests"; BRVTAL_SCOPE_RUN_DB=true ;;
      tests/e2e/*-real-stack.spec.mjs|tests/e2e/run-content-core-real-stack.sh)
        brvtal_ci_scope_add_area "Real-stack tests"; BRVTAL_SCOPE_RUN_REALSTACK=true ;;
      tests/e2e/discadmin-totp-login.spec.mjs)
        brvtal_ci_scope_add_area "Auth browser tests"; BRVTAL_SCOPE_RUN_BROWSER=true; BRVTAL_SCOPE_RUN_WEBKIT=true ;;
      tests/e2e/*)
        brvtal_ci_scope_add_area "Browser tests"; BRVTAL_SCOPE_RUN_BROWSER=true ;;
      playwright.config.mjs|package.json|package-lock.json)
        brvtal_ci_scope_add_area "Test tooling"; BRVTAL_SCOPE_RUN_PHP=true; BRVTAL_SCOPE_RUN_JS=true; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_BROWSER=true; BRVTAL_SCOPE_RUN_REALSTACK=true; BRVTAL_SCOPE_RUN_WEBKIT=true ;;
      .github/workflows/update-release-metadata.yml|scripts/ci-scope.sh)
        brvtal_ci_scope_add_area "CI/CD core"; BRVTAL_SCOPE_RUN_PHP=true; BRVTAL_SCOPE_RUN_JS=true; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_BROWSER=true; BRVTAL_SCOPE_RUN_REALSTACK=true; BRVTAL_SCOPE_RUN_WEBKIT=true; BRVTAL_SCOPE_RUN_RECOVERY=true ;;
      .github/*)
        brvtal_ci_scope_add_area "CI/CD" ;;
      .coderabbit.yaml|.sonarcloud.properties)
        brvtal_ci_scope_add_area "Review/static analysis policy" ;;
      scripts/*)
        brvtal_ci_scope_add_area "Fast tests/tooling"; BRVTAL_SCOPE_RUN_PHP=true; BRVTAL_SCOPE_RUN_JS=true ;;
      tests/*.php)
        brvtal_ci_scope_add_area "Fast tests/tooling"; BRVTAL_SCOPE_RUN_PHP=true ;;
      docs/*|README.md|AGENTS.md)
        brvtal_ci_scope_add_area "Docs/operations" ;;
      *)
        brvtal_ci_scope_add_area "Other"; BRVTAL_SCOPE_RUN_PHP=true; BRVTAL_SCOPE_RUN_JS=true; BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_BROWSER=true; BRVTAL_SCOPE_RUN_REALSTACK=true ;;
    esac

    case "$file" in
      *.php) BRVTAL_SCOPE_RUN_PHP=true ;;
      *.js|*.mjs) BRVTAL_SCOPE_RUN_JS=true ;;
    esac

    case "$file" in
      config/backups.php|discadmin/backups.php|tests/backups-contract.php|tests/backup-recovery-rehearsal-contract.php)
        BRVTAL_SCOPE_RUN_DB=true; BRVTAL_SCOPE_RUN_RECOVERY=true ;;
    esac

    case "$file" in
      config/admin_auth.php|config/totp*.php|discadmin/totp*|discadmin/index.php|api/index.php)
        BRVTAL_SCOPE_RUN_WEBKIT=true ;;
    esac
  done <<< "$changed_file_list"

  if [[ -z "$BRVTAL_SCOPE_AREAS" ]]; then
    BRVTAL_SCOPE_AREAS="None detected"
  fi
  return 0
}

brvtal_ci_scope_print() {
  printf 'full=%s\n' "$BRVTAL_SCOPE_FULL"
  printf 'run_php=%s\n' "$BRVTAL_SCOPE_RUN_PHP"
  printf 'run_js=%s\n' "$BRVTAL_SCOPE_RUN_JS"
  printf 'run_db=%s\n' "$BRVTAL_SCOPE_RUN_DB"
  printf 'run_browser=%s\n' "$BRVTAL_SCOPE_RUN_BROWSER"
  printf 'run_realstack=%s\n' "$BRVTAL_SCOPE_RUN_REALSTACK"
  printf 'run_webkit=%s\n' "$BRVTAL_SCOPE_RUN_WEBKIT"
  printf 'run_recovery=%s\n' "$BRVTAL_SCOPE_RUN_RECOVERY"
  printf 'areas=%s\n' "$BRVTAL_SCOPE_AREAS"
}
