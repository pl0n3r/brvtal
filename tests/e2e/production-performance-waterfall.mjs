const roundMs = (value) => Number.isFinite(value) ? Math.round(value * 10) / 10 : null;

function parseUrl(value, base) {
  try {
    return new URL(value, base || undefined);
  } catch {
    return null;
  }
}

function isStaticPath(pathname) {
  return /\.(?:css|js|mjs|woff2?|png|jpe?g|webp|avif|gif|svg|mp4|webm)$/i.test(pathname || '');
}

function isTextResponse(contentType) {
  return /(?:^text\/|javascript|json|xml|svg\+xml)/i.test(contentType || '');
}

export function buildPerformanceWaterfall(resources = [], responseMeta = {}, finalUrl = '', limit = 8) {
  const final = parseUrl(finalUrl, finalUrl);
  const origin = final?.origin || '';

  const rows = resources.map((resource) => {
    const url = String(resource?.name || '');
    const parsed = parseUrl(url, finalUrl);
    const meta = responseMeta[url] || {};
    const cacheControl = String(meta.cacheControl || '');
    const contentEncoding = String(meta.contentEncoding || '');
    const contentType = String(meta.contentType || '');
    const duration = Number.isFinite(resource?.duration)
      ? resource.duration
      : (Number.isFinite(resource?.responseEnd) && Number.isFinite(resource?.startTime)
          ? Math.max(0, resource.responseEnd - resource.startTime)
          : null);

    return {
      url,
      initiatorType: resource?.initiatorType || 'other',
      startTime: roundMs(resource?.startTime),
      duration: roundMs(duration),
      requestStart: roundMs(resource?.requestStart),
      responseStart: roundMs(resource?.responseStart),
      responseEnd: roundMs(resource?.responseEnd),
      transferSize: Number.isFinite(resource?.transferSize) ? resource.transferSize : null,
      encodedBodySize: Number.isFinite(resource?.encodedBodySize) ? resource.encodedBodySize : null,
      decodedBodySize: Number.isFinite(resource?.decodedBodySize) ? resource.decodedBodySize : null,
      status: Number.isFinite(meta.status) ? meta.status : null,
      cacheControl: cacheControl || null,
      contentEncoding: contentEncoding || null,
      contentType: contentType || null,
      firstParty: Boolean(origin && parsed?.origin === origin),
      versioned: Boolean(parsed?.searchParams?.has('v')),
      staticAsset: isStaticPath(parsed?.pathname || ''),
      textResponse: isTextResponse(contentType),
    };
  }).sort((a, b) => (a.startTime ?? Number.POSITIVE_INFINITY) - (b.startTime ?? Number.POSITIVE_INFINITY));

  const slowest = [...rows]
    .filter((row) => row.duration != null)
    .sort((a, b) => (b.duration ?? 0) - (a.duration ?? 0))
    .slice(0, limit);

  const heaviest = [...rows]
    .filter((row) => row.transferSize != null)
    .sort((a, b) => (b.transferSize ?? 0) - (a.transferSize ?? 0))
    .slice(0, limit);

  const versionedStatic = rows.filter((row) => row.firstParty && row.versioned && row.staticAsset);
  const compressedText = rows.filter((row) => row.firstParty && row.textResponse);

  return {
    resources: rows,
    slowest,
    heaviest,
    cache: {
      versionedStaticCount: versionedStatic.length,
      immutableVersionedStaticCount: versionedStatic.filter((row) => /(?:^|,)\s*immutable(?:,|$)/i.test(row.cacheControl || '')).length,
      firstPartyTextCount: compressedText.length,
      compressedFirstPartyTextCount: compressedText.filter((row) => /^(?:br|gzip|deflate|zstd)$/i.test(row.contentEncoding || '')).length,
    },
  };
}
