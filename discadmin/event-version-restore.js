(() => {
  'use strict';

  const RESTORABLE_FIELDS = Object.freeze([
    'title',
    'slug',
    'event_date',
    'archive_year',
    'venue',
    'city',
    'description',
    'skin',
    'accent',
    'cover_image',
    'ticket_url',
    'ticket_instructions',
    'ticket_qr',
    'featured',
    'seo_title',
    'seo_description',
  ]);

  const restorable = new Set(RESTORABLE_FIELDS);

  function plainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
  }

  function positiveId(value) {
    const id = Number(value);
    return Number.isInteger(id) && id > 0 ? id : 0;
  }

  function safeScalar(value) {
    return value === null || ['string', 'number', 'boolean'].includes(typeof value);
  }

  function equalValue(left, right) {
    return Object.is(left, right) || (left == null && right == null);
  }

  function failure(code) {
    return Object.freeze({ok:false, code});
  }

  function inspectRestoreSource(historyItem, currentEvent) {
    if (!plainObject(historyItem) || !plainObject(currentEvent)) return failure('INVALID_INPUT');
    if (String(historyItem.resource || '').toLowerCase() !== 'events') return failure('WRONG_RESOURCE');
    if (String(historyItem.action || '').toLowerCase() !== 'update') return failure('UNSUPPORTED_HISTORY_ACTION');

    const eventId = positiveId(currentEvent.id);
    const resourceId = positiveId(historyItem.resource_id);
    const activityId = positiveId(historyItem.id);
    if (!eventId || !resourceId || eventId !== resourceId || !activityId) return failure('EVENT_MISMATCH');

    const target = historyItem.after;
    if (!plainObject(target) || positiveId(target.id) !== eventId) return failure('INCOMPATIBLE_SNAPSHOT');

    return {ok:true, eventId, activityId, target};
  }

  function collectRestorableValues(target, currentEvent) {
    const values = {};
    const changes = [];

    for (const field of RESTORABLE_FIELDS) {
      if (!Object.hasOwn(target, field)) continue;
      const next = target[field];
      if (!safeScalar(next)) return failure('INCOMPATIBLE_SNAPSHOT');

      values[field] = next;
      const previous = Object.hasOwn(currentEvent, field) ? currentEvent[field] : null;
      if (!equalValue(previous, next)) {
        changes.push(Object.freeze({field, from:previous, to:next}));
      }
    }

    return {ok:true, values, changes};
  }

  function compatibleOpaqueFields(target) {
    for (const [field, value] of Object.entries(target)) {
      if (field === 'id' || restorable.has(field)) continue;
      if (!safeScalar(value) && value !== undefined) return false;
    }
    return true;
  }

  function buildPlan(historyItem, currentEvent) {
    const source = inspectRestoreSource(historyItem, currentEvent);
    if (!source.ok) return source;

    const restore = collectRestorableValues(source.target, currentEvent);
    if (!restore.ok) return restore;
    if (!compatibleOpaqueFields(source.target)) return failure('INCOMPATIBLE_SNAPSHOT');
    if (Object.keys(restore.values).length === 0 || restore.changes.length === 0) return failure('NO_SAFE_CHANGES');

    return Object.freeze({
      ok:true,
      event_id:source.eventId,
      activity_id:source.activityId,
      source_created_at:String(historyItem.created_at || ''),
      fields:Object.freeze(Object.keys(restore.values)),
      values:Object.freeze({...restore.values}),
      changes:Object.freeze(restore.changes),
      execution:false,
    });
  }

  window.BRVTALEventVersionRestore = Object.freeze({
    RESTORABLE_FIELDS,
    buildPlan,
  });
})();
