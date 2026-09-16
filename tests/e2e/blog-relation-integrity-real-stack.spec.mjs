import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL_REAL_STACK_URL and admin credentials are required for the real-stack smoke');

test('Blog rejects dangling related content without partially updating the post', async ({ page }, testInfo) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: { email: adminEmail, password: adminPassword },
  });
  expect(login.ok()).toBeTruthy();
  const auth = await login.json();
  const headers = { 'X-CSRF-Token': auth.csrf };
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  let postId = 0;
  let danglingPostId = 0;

  const artistsResponse = await page.request.get(`${baseUrl}/api/index.php/artists`);
  expect(artistsResponse.ok()).toBeTruthy();
  const artists = (await artistsResponse.json()).data || [];
  const artist = artists.find(row => row.slug === 'pl0n3r-smoke');
  expect(Number(artist?.id || 0)).toBeGreaterThan(0);

  const readPost = async id => {
    const response = await page.request.get(`${baseUrl}/api/blog.php?id=${id}`);
    expect(response.ok()).toBeTruthy();
    return (await response.json()).data;
  };

  try {
    const originalTitle = `CI BLOG RELATION ${runKey}`;
    const originalSlug = `ci-blog-relation-${runKey}`;
    const create = await page.request.post(`${baseUrl}/api/blog.php`, {
      headers,
      data: {
        title: originalTitle,
        slug: originalSlug,
        body: 'Blog relation integrity fixture.',
        status: 'draft',
        relations: [{ related_type: 'artist', related_id: Number(artist.id), sort_order: 0 }],
      },
    });
    expect(create.status()).toBe(201);
    const created = await create.json();
    postId = Number(created?.data?.id || 0);
    expect(postId).toBeGreaterThan(0);
    expect(created.data.relations).toEqual([
      expect.objectContaining({ related_type: 'artist', related_id: Number(artist.id) }),
    ]);

    const invalidUpdate = await page.request.put(`${baseUrl}/api/blog.php?id=${postId}`, {
      headers,
      data: {
        title: `${originalTitle} SHOULD ROLLBACK`,
        slug: originalSlug,
        body: 'This body must not persist.',
        status: 'published',
        relations: [{ related_type: 'artist', related_id: 999999, sort_order: 0 }],
      },
    });
    expect(invalidUpdate.status()).toBe(422);
    expect(await invalidUpdate.json()).toMatchObject({ ok: false, error: 'BLOG_RELATION_NOT_FOUND' });

    const afterRejectedUpdate = await readPost(postId);
    expect(afterRejectedUpdate.title).toBe(originalTitle);
    expect(afterRejectedUpdate.status).toBe('draft');
    expect(afterRejectedUpdate.body).toBe('Blog relation integrity fixture.');
    expect(afterRejectedUpdate.relations).toEqual([
      expect.objectContaining({ related_type: 'artist', related_id: Number(artist.id) }),
    ]);

    const danglingSlug = `ci-dangling-blog-${runKey}`;
    const invalidCreate = await page.request.post(`${baseUrl}/api/blog.php`, {
      headers,
      data: {
        title: `CI DANGLING BLOG ${runKey}`,
        slug: danglingSlug,
        status: 'draft',
        relations: [{ related_type: 'release', related_id: 999999, sort_order: 0 }],
      },
    });
    const invalidCreateBody = await invalidCreate.json();
    danglingPostId = Number(invalidCreateBody?.data?.id || 0);
    expect(invalidCreate.status()).toBe(422);
    expect(invalidCreateBody).toMatchObject({ ok: false, error: 'BLOG_RELATION_NOT_FOUND' });

    const list = await page.request.get(`${baseUrl}/api/blog.php`);
    expect(list.ok()).toBeTruthy();
    expect(((await list.json()).data || []).some(row => row.slug === danglingSlug)).toBe(false);
  } finally {
    if (danglingPostId > 0 && danglingPostId !== postId) {
      await page.request.delete(`${baseUrl}/api/blog.php?id=${danglingPostId}`, { headers }).catch(() => {});
    }
    if (postId > 0) {
      await page.request.delete(`${baseUrl}/api/blog.php?id=${postId}`, { headers }).catch(() => {});
    }
  }
});
