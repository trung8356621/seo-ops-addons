/**
 * Reviews sync UI + dynamic lock isolation contracts.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const addonsRoot = path.resolve(root, '../../..');
const tab = fs.readFileSync(path.join(root, 'components/ArticleReviewsTab.jsx'), 'utf8');
const lockCli = fs.readFileSync(path.join(addonsRoot, 'content/scripts/widget-lock.cjs'), 'utf8');
const manifest = JSON.parse(
    fs.readFileSync(path.join(addonsRoot, 'content/editor-widget-locks.json'), 'utf8'),
);

assert.match(tab, /syncable_pending_count/);
assert.match(tab, /local_generated_count/);
assert.match(tab, /generated_count/);
assert.match(tab, /Review được kiểm tra và tạo tự động khi đồng bộ WordPress/);
assert.doesNotMatch(tab, /Create reviews/);
assert.doesNotMatch(tab, /Sync pending reviews/);
assert.doesNotMatch(tab, /ContentProjectPublishing/);
assert.doesNotMatch(tab, /Publish Now/);

assert.match(lockCli, /No unlock-all/);
assert.match(lockCli, /widget\.locked = false/);
assert.ok(manifest.widgets.reviews, 'reviews widget must exist in manifest');
assert.equal(typeof manifest.widgets.reviews.locked, 'boolean');

console.log('productReviewSyncWidget.selftest: ok');
