/**
 * IndexedDB proof binary store.
 * Business JSON keeps proof_id only — never Blob/base64 in localStorage.
 */

const DB_NAME = 'seeding-proofs-v1';
const STORE = 'proofs';
const DB_VERSION = 1;

function openDb() {
    return new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') {
            reject(new Error('IndexedDB không khả dụng.'));
            return;
        }
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'id' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error || new Error('Không mở được IndexedDB.'));
    });
}

/**
 * @param {{ id: string, blob: Blob, mime: string, size: number, created_at: string, topic_id?: string, comment_item_id?: string }} record
 */
export async function saveProof(record) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.oncomplete = () => resolve(record);
        tx.onerror = () => reject(tx.error || new Error('Lưu proof thất bại.'));
        tx.objectStore(STORE).put(record);
    });
}

/**
 * @param {string} id
 * @returns {Promise<{ id: string, blob: Blob, mime: string, size: number, created_at: string }|null>}
 */
export async function getProof(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readonly');
        const req = tx.objectStore(STORE).get(id);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error || new Error('Đọc proof thất bại.'));
    });
}

/**
 * @param {string} id
 */
export async function deleteProof(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.oncomplete = () => resolve(true);
        tx.onerror = () => reject(tx.error || new Error('Xóa proof thất bại.'));
        tx.objectStore(STORE).delete(id);
    });
}

/**
 * @param {DataTransfer|ClipboardEvent['clipboardData']} clipboardData
 * @returns {File|null}
 */
export function extractImageFromClipboard(clipboardData) {
    if (!clipboardData) return null;
    const items = clipboardData.items ? [...clipboardData.items] : [];
    for (const item of items) {
        if (item.kind === 'file' && /^image\/(png|jpeg|jpg|webp)$/i.test(item.type)) {
            const file = item.getAsFile();
            if (file) return file;
        }
    }
    const files = clipboardData.files ? [...clipboardData.files] : [];
    for (const file of files) {
        if (/^image\/(png|jpeg|jpg|webp)$/i.test(file.type)) return file;
    }
    return null;
}
