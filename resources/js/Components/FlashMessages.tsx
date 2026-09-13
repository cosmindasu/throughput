import { usePage } from '@inertiajs/react';

/**
 * Mesajele `flash.success` / `flash.error` / `flash.notice` din props comune
 * (HandleInertiaRequests). `role="status"` pentru confirmări și notificări discrete,
 * `role="alert"` pentru erori: un refuz de server (ex: „Set a deal value before marking
 * as Won") trebuie anunțat, nu doar colorat. `notice` (FR-VIEW-02 — vederea Team folosită
 * ca implicit a fost ștearsă) e tonul `info`, nu `danger`: nu e o greșeală a cui o vede.
 */
export default function FlashMessages() {
    const { flash } = usePage().props;

    if (!flash.success && !flash.error && !flash.notice) {
        return null;
    }

    return (
        <div className="mb-4 flex flex-col gap-2">
            {flash.success && (
                <p role="status" className="rounded-md bg-success-tint px-3 py-2 text-sm text-success">
                    {flash.success}
                </p>
            )}
            {flash.notice && (
                <p role="status" className="rounded-md bg-info-tint px-3 py-2 text-sm text-info">
                    {flash.notice}
                </p>
            )}
            {flash.error && (
                <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                    {flash.error}
                </p>
            )}
        </div>
    );
}
