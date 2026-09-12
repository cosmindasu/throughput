import { usePage } from '@inertiajs/react';

/**
 * Mesajele `flash.success` / `flash.error` din props comune (HandleInertiaRequests).
 * `role="status"` pentru confirmări, `role="alert"` pentru erori: un refuz de server
 * (ex: „Set a deal value before marking as Won") trebuie anunțat, nu doar colorat.
 */
export default function FlashMessages() {
    const { flash } = usePage().props;

    if (!flash.success && !flash.error) {
        return null;
    }

    return (
        <div className="mb-4 flex flex-col gap-2">
            {flash.success && (
                <p role="status" className="rounded-md bg-success-tint px-3 py-2 text-sm text-success">
                    {flash.success}
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
