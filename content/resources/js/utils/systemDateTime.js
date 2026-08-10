/**
 * Canonical browser datetime helpers — same config payload as PHP SystemDateTime.
 *
 * Config shape:
 * {
 *   timezone, preset, locale, date_format, time_format,
 *   hour_cycle, first_day_of_week, offset_label?, timezone_chip?
 * }
 */

const DEFAULT_CONFIG = {
  timezone: 'Asia/Ho_Chi_Minh',
  preset: 'vi',
  locale: 'vi-VN',
  date_format: 'dd/MM/yyyy',
  time_format: 'HH:mm',
  hour_cycle: 'h23',
  first_day_of_week: 1,
  offset_label: 'UTC+07:00',
  timezone_chip: 'Asia/Ho_Chi_Minh · UTC+07:00',
};

let runtimeConfig = null;

export function setSystemDateTimeConfig(config) {
  runtimeConfig = config && typeof config === 'object' ? { ...DEFAULT_CONFIG, ...config } : null;
}

export function getSystemDateTimeConfig() {
  if (runtimeConfig) {
    return runtimeConfig;
  }
  if (typeof window !== 'undefined' && window.__SYSTEM_DATETIME__ && typeof window.__SYSTEM_DATETIME__ === 'object') {
    return { ...DEFAULT_CONFIG, ...window.__SYSTEM_DATETIME__ };
  }
  return { ...DEFAULT_CONFIG };
}

function toDate(value) {
  if (value == null || value === '') {
    return null;
  }
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value;
  }
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

function partsInZone(date, timeZone) {
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  });
  const bag = {};
  for (const p of fmt.formatToParts(date)) {
    if (p.type !== 'literal') {
      bag[p.type] = p.value;
    }
  }
  return {
    year: Number(bag.year),
    month: Number(bag.month),
    day: Number(bag.day),
    hour: Number(bag.hour),
    minute: Number(bag.minute),
    second: Number(bag.second ?? '0'),
  };
}

export function toSystemDate(value) {
  const d = toDate(value);
  if (!d) {
    return null;
  }
  const cfg = getSystemDateTimeConfig();
  return partsInZone(d, cfg.timezone);
}

export function formatDate(value) {
  const d = toDate(value);
  if (!d) {
    return null;
  }
  const cfg = getSystemDateTimeConfig();
  if (cfg.preset === 'en') {
    return new Intl.DateTimeFormat(cfg.locale, {
      timeZone: cfg.timezone,
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    }).format(d);
  }
  const p = partsInZone(d, cfg.timezone);
  return `${String(p.day).padStart(2, '0')}/${String(p.month).padStart(2, '0')}/${p.year}`;
}

export function formatTime(value) {
  const d = toDate(value);
  if (!d) {
    return null;
  }
  const cfg = getSystemDateTimeConfig();
  if (cfg.preset === 'en') {
    return new Intl.DateTimeFormat(cfg.locale, {
      timeZone: cfg.timezone,
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    }).format(d);
  }
  const p = partsInZone(d, cfg.timezone);
  return `${String(p.hour).padStart(2, '0')}:${String(p.minute).padStart(2, '0')}`;
}

export function formatDateTime(value) {
  const date = formatDate(value);
  const time = formatTime(value);
  if (!date || !time) {
    return null;
  }
  return `${date} ${time}`;
}

export function formatRelative(value, now = new Date()) {
  const d = toDate(value);
  const n = toDate(now);
  if (!d || !n) {
    return null;
  }
  const cfg = getSystemDateTimeConfig();
  const diffSec = Math.round((d.getTime() - n.getTime()) / 1000);
  const abs = Math.abs(diffSec);
  const rtf = new Intl.RelativeTimeFormat(cfg.locale, { numeric: 'auto' });

  if (abs < 60) {
    return rtf.format(Math.trunc(diffSec), 'second');
  }
  if (abs < 3600) {
    return rtf.format(Math.trunc(diffSec / 60), 'minute');
  }
  if (abs < 86400) {
    return rtf.format(Math.trunc(diffSec / 3600), 'hour');
  }
  return rtf.format(Math.trunc(diffSec / 86400), 'day');
}

export function formatForDateTimeInput(value) {
  const p = toSystemDate(value);
  if (!p) {
    return null;
  }
  return `${p.year}-${String(p.month).padStart(2, '0')}-${String(p.day).padStart(2, '0')}T${String(p.hour).padStart(2, '0')}:${String(p.minute).padStart(2, '0')}`;
}

export function nowInSystemTimezone() {
  return toSystemDate(new Date());
}

/**
 * Parse system-local input → UTC ISO string (Z).
 * Accepts datetime-local or ISO. Does not double-convert when value already has Z/offset.
 */
export function parseSystemInputToUtc(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error('Datetime input is required.');
  }
  const raw = value.trim();
  if (/[Zz]$|[+-]\d{2}:?\d{2}$/.test(raw)) {
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) {
      throw new Error(`Invalid datetime input: ${raw}`);
    }
    return d.toISOString();
  }

  const cfg = getSystemDateTimeConfig();
  const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!m) {
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) {
      throw new Error(`Invalid datetime input: ${raw}`);
    }
    return d.toISOString();
  }

  const asUtcGuess = Date.UTC(
    Number(m[1]),
    Number(m[2]) - 1,
    Number(m[3]),
    Number(m[4]),
    Number(m[5]),
    Number(m[6] || 0),
  );
  const probe = new Date(asUtcGuess);
  const parts = partsInZone(probe, cfg.timezone);
  const wanted = {
    year: Number(m[1]),
    month: Number(m[2]),
    day: Number(m[3]),
    hour: Number(m[4]),
    minute: Number(m[5]),
    second: Number(m[6] || 0),
  };
  const deltaMin =
    (((wanted.year - parts.year) * 12 + (wanted.month - parts.month)) * 30 + (wanted.day - parts.day)) * 24 * 60
    + (wanted.hour - parts.hour) * 60
    + (wanted.minute - parts.minute);
  const corrected = new Date(asUtcGuess + deltaMin * 60 * 1000);
  // refine once more for DST edges
  const parts2 = partsInZone(corrected, cfg.timezone);
  const delta2 =
    (wanted.hour - parts2.hour) * 60
    + (wanted.minute - parts2.minute)
    + (wanted.day - parts2.day) * 24 * 60;
  const finalDate = new Date(corrected.getTime() + delta2 * 60 * 1000);
  return finalDate.toISOString();
}

if (typeof window !== 'undefined') {
  window.SystemDateTime = {
    getSystemDateTimeConfig,
    setSystemDateTimeConfig,
    formatDate,
    formatTime,
    formatDateTime,
    formatRelative,
    toSystemDate,
    parseSystemInputToUtc,
    formatForDateTimeInput,
    nowInSystemTimezone,
  };
}
