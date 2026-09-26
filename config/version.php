<?php
declare(strict_types=1);

/**
 * BRVTAL product release metadata.
 *
 * Every deploy-bound PR increments BRVTAL_APP_VERSION before final gates.
 * Patch is the default; pre-1.0 minor bumps are deliberate milestones.
 * BRVTAL 1.0.0 requires an explicit administrator decision.
 *
 * BRVTAL_APP_BUILD remains compatibility-only fallback metadata and must not
 * be presented as an exact deployed Git SHA unless the resolver confirms an
 * environment/git-checkout source.
 */
const BRVTAL_APP_VERSION = '0.1.62';
const BRVTAL_APP_BUILD = '0103bbc';
const BRVTAL_APP_ENV = 'PRODUCTION';
const BRVTAL_RELEASE_DATE = '2026-09-26';
