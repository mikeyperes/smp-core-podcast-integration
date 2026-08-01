# MPP content-kind migration (review artifact)

This directory contains an inert, WP-CLI-only migration. Nothing here is
loaded by the plugin, activation, cron, REST, or an HTTP request. The 158
published, 6 draft, and 1 private counts are review evidence, not identity.
The migration authority is a separately reviewed exact-ID seed.

## Required ID seed

Planning requires a JSON file with this shape:

```json
{
  "format": "mpp-content-kind-id-seed-v1",
  "site_url": "https://podcast.michaelperes.com",
  "ids": [101, 202, 303],
  "ids_sha256": "64-lowercase-hex-characters"
}
```

The example IDs are illustrative only; a valid seed must contain all 165 real
IDs as positive JSON integers in ascending order. `ids_sha256` is SHA-256 of
the ordered decimal IDs, one per line, including the final newline. Prepare
and review this list outside the migration runner, record its checksum, then
make the JSON file read-only (for example, mode `0400`). The planner refuses a
writable file, a symlink, a changed checksum, a replacement ID, or a live
corpus that differs from the reviewed list even when all status counts remain
158/6/1. The corpus query also includes pending, scheduled (`future`), and any
other registered active post status; each must contain zero additional posts.
Trash, auto-drafts, and revision inheritance are excluded.

Do not derive or approve the seed in the same mutation step. Review every ID
against its title, permalink, status, and episode evidence first.

## Required production order after code review

1. Deploy the reviewed ScaleMyPodcast release that registers
   `_mpp_content_kind`; verify the package hash and integration diagnostics.
2. Put the site in a controlled editorial window so IDs, statuses, titles,
   permalinks, legacy markers, and content-kind rows cannot drift.
3. Copy the two migration PHP files to a non-public operator path.
4. Supply the immutable seed to `plan`, for example:

   ```text
   wp eval-file tools/mpp-content-kind-migration.php plan -- --seed=/secure/reviewed-mpp-ids.json --manifest=/secure/mpp-content-kind-manifest.json
   ```

   Planning is mutation-free. It compares the complete live corpus to the
   exact seed and writes a checksummed manifest containing all IDs plus each
   title, permalink, status, legacy marker keys, and complete prior meta-row
   state.
5. Review every manifest entry and both the exact-ID and manifest checksums.
6. Run `apply` without `--execute`; this must pass as a dry run.
7. Back up the database, then run `apply` with the exact site URL,
   `--execute`, and confirmation token `BACKFILL_MPP_EPISODES_165`.
8. Verify exactly one `episode` row for every reviewed ID, Elementor condition
   behavior, both single templates, PowerPress, feeds, persistent playback,
   and that `smp_podcast_legacy_marker_fallback_enabled` is explicitly disabled
   before ending the editorial window. The guarded apply performs this cutover
   in the same transaction as the backfill so new unclassified articles cannot
   regain podcast behavior from incidental `enclosure` or audio metadata.
9. Retain the exact manifest. Rollback requires its checksum, the unchanged
   exact-ID corpus and review evidence, one current `episode` row per ID, the
   exact site URL, `--execute`, and token `ROLLBACK_MPP_EPISODES_165`.

The planner rejects duplicate existing `_mpp_content_kind` rows—identical or
conflicting—instead of silently collapsing them. Apply writes exactly one row;
rollback restores the manifest's exact absent-or-single-row prior state. A
database transaction and post-write row verification guard both directions.
Rollback also restores whether the legacy-fallback option existed and its
reviewed enabled/disabled state.

If the database ever refuses the transaction's SQL `ROLLBACK`, the command
reports an **indeterminate database state** instead of claiming recovery. Keep
the editorial window closed and restore/inspect the database backup before any
further migration command.
