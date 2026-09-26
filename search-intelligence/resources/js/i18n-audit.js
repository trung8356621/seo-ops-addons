// Generated bilingual catalog for UI strings migrated by the i18n audit.
const messages = {
  "en": {
    "audit_f85aa84dfa41": "Not tagged yet",
    "audit_e779488cf01c": "Topics have not been tagged yet"
  },
  "vi": {
    "audit_f85aa84dfa41": "Chưa gắn tag",
    "audit_e779488cf01c": "Các Topic chưa được gắn tag"
  }
};

function locale() {
  const value = typeof document !== 'undefined' ? document.documentElement?.lang : 'en';
  return String(value || 'en').toLowerCase().startsWith('vi') ? 'vi' : 'en';
}

export function auditT(key) {
  const current = locale();
  return messages[current]?.[key] ?? messages.en?.[key] ?? key;
}
