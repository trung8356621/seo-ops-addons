/**
 * React-only toast helpers — never Filament / Livewire / Laravel flash.
 */
import { toast } from 'sonner';

export function notifySuccess(message) {
    toast.success(String(message || ''));
}

export function notifyError(message) {
    toast.error(String(message || ''));
}

export function notifyWarning(message) {
    toast.warning(String(message || ''));
}

export function notifyInfo(message) {
    toast.message(String(message || ''));
}
